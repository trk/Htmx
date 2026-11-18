import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import {
	attachRuntimeErrorTracker,
	getAdminUrl,
	runWirePhp,
} from '../../../../../tests/e2e/helpers';

interface HtmxRuntimeMeta {
	endpointPath: string;
	cacheEnabled: boolean;
}

function getHtmxRuntimeMeta(): HtmxRuntimeMeta {
	return runWirePhp<HtmxRuntimeMeta>(`
		$htmx = $wire->modules->get('Htmx');
		echo json_encode([
			'endpointPath' => $htmx ? (string) $htmx->resolveNormalizedEndpointPath() : '/hx/req',
			'cacheEnabled' => $htmx ? (bool) $htmx->cacheEnabled : true,
		], JSON_UNESCAPED_SLASHES);
	`);
}

test.describe('Htmx: Admin Configuration, Runtime Helpers, Endpoint Security & WCAG 2.1 AA', () => {
	test('renders Htmx configuration screen without runtime or WCAG 2.1 AA violations and executes cache maintenance', async ({
		page,
	}) => {
		const tracker = attachRuntimeErrorTracker(page);

		await page.goto(getAdminUrl('module/edit?name=Htmx'));
		const configForm = page.locator('#ModuleEditForm');
		await expect(configForm).toBeVisible();

		await expect(configForm.locator('[name="endpointUrl"]')).toBeVisible();
		await expect(configForm.locator('[name="componentsPath"]')).toBeVisible();
		await expect(configForm.locator('[name="uiPath"]')).toBeVisible();
		await expect(configForm.locator('[name="allowComponentPaths"]')).toBeAttached();
		await expect(configForm.locator('[name="loadFrontendAssets"]')).toBeAttached();
		await expect(configForm.locator('[name="loadHyperscript"]')).toBeAttached();
		await expect(configForm.locator('[name="autoFlashMessages"]')).toBeAttached();
		await expect(configForm.locator('[name="autoExtractTargets"]')).toBeAttached();
		await expect(configForm.locator('[name="tracySupport"]')).toBeAttached();
		await expect(configForm.locator('[name="cacheEnabled"]')).toBeAttached();
		await expect(configForm.locator('[name="cacheMaxShapesPerClass"]')).toBeVisible();
		await expect(configForm.locator('[name="_htmx_cache_action"]').first()).toBeAttached();

		const axe = await new AxeBuilder({ page })
			.include('#ModuleEditForm')
			.exclude('.InputfieldAsmSelect')
			.exclude('#AdminDataTable1')
			.exclude('#wrap_Inputfield_submit_save_module')
			.withTags(['wcag2a', 'wcag2aa'])
			.analyze();
		expect(axe.violations).toEqual([]);

		// Verify HTMX v4 runtime & companion scripts are loaded and bound in ProcessWire Admin
		const runtimeState = await page.evaluate(() => {
			const w = window as unknown as {
				htmx?: unknown;
				ProcessWire?: { config?: { csrf?: Record<string, string>; pwHtmxConfigToken?: string } };
			};
			const scriptSrcs = Array.from(document.querySelectorAll('script[src]')).map(
				(s) => s.getAttribute('src') || ''
			);
			return {
				hasHtmxGlobal: typeof w.htmx === 'object' && w.htmx !== null,
				hasConfigToken:
					typeof w.ProcessWire?.config?.pwHtmxConfigToken === 'string' &&
					w.ProcessWire.config.pwHtmxConfigToken.length === 32,
				hasRuntimeJs: scriptSrcs.some((src) => src.includes('pw-htmx-runtime.js')),
				hasCsrfJs: scriptSrcs.some((src) => src.includes('pw-csrf.js')),
				hasGuardJs: scriptSrcs.some((src) => src.includes('pw-htmx-guard.js')),
			};
		});

		expect(runtimeState.hasHtmxGlobal).toBe(true);
		expect(runtimeState.hasConfigToken).toBe(true);
		expect(runtimeState.hasRuntimeJs).toBe(true);
		expect(runtimeState.hasCsrfJs).toBe(true);
		expect(runtimeState.hasGuardJs).toBe(true);

		// Execute non-destructive HtmxCache prune action via admin form POST
		await configForm.locator('input[name="_htmx_cache_action"][value="prune"]').check({ force: true });
		await page.locator('#Inputfield_submit_save_module').first().click();
		await expect(page.locator('#ModuleEditForm')).toBeVisible();

		// Ensure transient _htmx_cache_action was stripped before Modules::saveConfig persisted settings
		const savedCfg = runWirePhp<Record<string, unknown>>(`
			$cfg = $wire->modules->getConfig('Htmx');
			echo json_encode(is_array($cfg) ? $cfg : [], JSON_UNESCAPED_SLASHES);
		`);
		expect(Object.prototype.hasOwnProperty.call(savedCfg, '_htmx_cache_action')).toBe(false);

		tracker.assertNoErrors();
	});

	test('enforces /hx/req state payload validation (400 missing/malformed, 403 tampered HMAC) and dispatches deferred runtime events', async ({
		page,
	}) => {
		const tracker = attachRuntimeErrorTracker(page);
		const meta = getHtmxRuntimeMeta();

		await page.goto(getAdminUrl('module/edit?name=Htmx'));
		await expect(page.locator('#ModuleEditForm')).toBeVisible();

		// 1. Missing hx__state payload -> 400 Bad Request
		const missingRes = await page.request.post(meta.endpointPath, {
			headers: {
				'HX-Request': 'true',
			},
			form: {
				__htmx_action: 'increment',
			},
		});
		expect(missingRes.status()).toBe(400);
		expect(await missingRes.text()).toContain('Missing HTMX State Payload');

		// 2. Malformed hx__state payload (no pipe delimiter) -> 400 Bad Request
		const malformedRes = await page.request.post(meta.endpointPath, {
			headers: {
				'HX-Request': 'true',
			},
			form: {
				hx__state: 'malformed_payload_without_hmac',
			},
		});
		expect(malformedRes.status()).toBe(400);
		expect(await malformedRes.text()).toContain('Malformed HTMX State Payload');

		// 3. Tampered HMAC signature -> 403 Forbidden
		const forgedBase64 = Buffer.from(JSON.stringify({ __cmp: 'Htmx\\Component\\Fake', __id: 'x1' })).toString(
			'base64'
		);
		const tamperedRes = await page.request.post(meta.endpointPath, {
			headers: {
				'HX-Request': 'true',
			},
			form: {
				hx__state: `${forgedBase64}|0000000000000000000000000000000000000000000000000000000000000000`,
			},
		});
		expect(tamperedRes.status()).toBe(403);
		expect(await tamperedRes.text()).toContain('Invalid HTMX State Signature');

		// 4. Verify pw-htmx-runtime.js decodes tunneled __pwHtmxAfterSwap / __pwHtmxAfterSettle synthetic events in DOM
		const dispatched = await page.evaluate(() => {
			const log: string[] = [];
			document.body.addEventListener('e2e:swap:fired', (ev) => {
				const detail = (ev as CustomEvent<{ step?: string }>).detail;
				log.push(`swap:${detail?.step ?? ''}`);
			});
			document.body.addEventListener('e2e:settle:fired', (ev) => {
				const detail = (ev as CustomEvent<{ step?: string }>).detail;
				log.push(`settle:${detail?.step ?? ''}`);
			});

			document.body.dispatchEvent(
				new CustomEvent('__pwHtmxAfterSwap', {
					bubbles: true,
					detail: { events: { 'e2e:swap:fired': { step: '1' } } },
				})
			);
			document.body.dispatchEvent(
				new CustomEvent('__pwHtmxAfterSettle', {
					bubbles: true,
					detail: { events: { 'e2e:settle:fired': { step: '2' } } },
				})
			);
			document.body.dispatchEvent(new CustomEvent('htmx:after:swap', { bubbles: true, detail: { target: document.body } }));
			document.body.dispatchEvent(new CustomEvent('htmx:after:settle', { bubbles: true, detail: { target: document.body } }));

			return log;
		});

		expect(dispatched).toEqual(['swap:1', 'settle:2']);

		tracker.assertNoErrors();
	});
});
