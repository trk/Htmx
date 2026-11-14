<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Tests\Unit;

use ProcessWire\HookEvent;
use ProcessWire\Htmx;
use ProcessWire\Page;
use ProcessWire\Wire;
use ProcessWire\WireCSRFException;
use ProcessWire\WireException;
use ProcessWire\WirePermissionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Totoglu\Htmx\Bag\AttributeBag;
use Totoglu\Htmx\Cache\HtmxCache;
use Totoglu\Htmx\Commands\MakeHtmxComponentCommand;
use Totoglu\Htmx\Commands\MakeHtmxUiCommand;
use Totoglu\Htmx\Component;
use Totoglu\Htmx\Fragment;
use Totoglu\Htmx\State\SignedStateCodec;
use Totoglu\Htmx\Ui;
use Totoglu\Htmx\View\ConfinedViewRenderer;

use function ProcessWire\wire;

describe('AttributeBag Security Hardening (HTMX v4)', function (): void {
	it('blocks protocol-relative, backslash, entity-encoded control-character, and cross-origin URLs in sanitizeHxUrl()', function (): void {
		$config = wire()->config;
		$prevHost = $config->httpHost;
		$prevHosts = $config->httpHosts;
		$prevHttps = $config->https;

		try {
			$config->httpHost = 'app.example.com';
			$config->httpHosts = ['app.example.com', 'cdn.app.example.com:8443'];
			$config->https = true;

			// Blocked vectors -> must return '/'
			expect(AttributeBag::sanitizeHxUrl('//evil.example/steal'))->toBe('/')
				->and(AttributeBag::sanitizeHxUrl('&#47;&#47;evil.example/steal'))->toBe('/')
				->and(AttributeBag::sanitizeHxUrl('/safe\..\..\evil'))->toBe('/')
				->and(AttributeBag::sanitizeHxUrl('/safe&#92;evil'))->toBe('/')
				->and(AttributeBag::sanitizeHxUrl("java&#x09;script:alert(1)"))->toBe('/')
				->and(AttributeBag::sanitizeHxUrl("&#x0a;/newline-injected"))->toBe('/')
				->and(AttributeBag::sanitizeHxUrl('/path&#x20;with-space'))->toBe('/')
				->and(AttributeBag::sanitizeHxUrl("/path\x00null"))->toBe('/')
				->and(AttributeBag::sanitizeHxUrl('https://evil.example/endpoint'))->toBe('/')
				->and(AttributeBag::sanitizeHxUrl('http://app.example.com/downgrade'))->toBe('/')
				->and(AttributeBag::sanitizeHxUrl('https://user:pass@app.example.com/secret'))->toBe('/')
				->and(AttributeBag::sanitizeHxUrl('ftp://app.example.com/file'))->toBe('/');

			// Allowed relative and same-origin URLs
			expect(AttributeBag::sanitizeHxUrl('/hx/req?tab=overview&page=2'))->toBe('/hx/req?tab=overview&page=2')
				->and(AttributeBag::sanitizeHxUrl('?filter=active'))->toBe('?filter=active')
				->and(AttributeBag::sanitizeHxUrl('#details'))->toBe('#details')
				->and(AttributeBag::sanitizeHxUrl('https://app.example.com/hx/req'))->toBe('https://app.example.com/hx/req')
				->and(AttributeBag::sanitizeHxUrl('https://cdn.app.example.com:8443/hx/req'))->toBe('https://cdn.app.example.com:8443/hx/req');
		} finally {
			$config->httpHost = $prevHost;
			$config->httpHosts = $prevHosts;
			$config->https = $prevHttps;
		}
	});

	it('preserves boolean true/false strings on hx(push-url) and hx(replace-url) with set() as the single choke point', function (): void {
		$bag = (new AttributeBag())
			->hx('push-url', 'true')
			->hx('replace-url', 'false')
			->hx('action', '//evil.example/hijack');

		expect($bag->get('hx-push-url'))->toBe('true')
			->and($bag->get('hx-replace-url'))->toBe('false')
			->and($bag->get('hx-action'))->toBe('/')
			->and($bag->render())->toBe('hx-push-url="true" hx-replace-url="false" hx-action="/"');

		$ui = new class extends Ui {
			public function render(): string
			{
				return '<a' . $this->attributes->renderRuntimeAttributes() . '>Link</a>';
			}
		};
		$ui->hx('push-url', 'true')
			->hx('replace-url', 'false')
			->pushUrl('//evil.example/hijack');

		$html = (string) $ui;
		expect($html)->toContain('hx-push-url="/"')
			->and($html)->toContain('hx-replace-url="false"');

		$uiBoolString = new class extends Ui {
			public function render(): string
			{
				return '<a' . $this->attributes->renderRuntimeAttributes() . '>Link</a>';
			}
		};
		$uiBoolString->hx('push-url', 'true')->replaceUrl('false');
		expect((string) $uiBoolString)->toContain('hx-push-url="true"')
			->and((string) $uiBoolString)->toContain('hx-replace-url="false"');
	});

	it('rejects raw on* and (data-)hx-on* event-handler attributes in normalizeAttributeName() and set()', function (): void {
		expect(AttributeBag::normalizeAttributeName('onclick'))->toBeNull()
			->and(AttributeBag::normalizeAttributeName('ONLOAD'))->toBeNull()
			->and(AttributeBag::normalizeAttributeName('onmouseover'))->toBeNull()
			->and(AttributeBag::normalizeAttributeName('hx-on:click'))->toBeNull()
			->and(AttributeBag::normalizeAttributeName('hx-on:htmx:after:request'))->toBeNull()
			->and(AttributeBag::normalizeAttributeName('hx-on-click'))->toBeNull()
			->and(AttributeBag::normalizeAttributeName('hx-on--after-request'))->toBeNull()
			->and(AttributeBag::normalizeAttributeName('data-hx-on:click'))->toBeNull()
			->and(AttributeBag::normalizeAttributeName('data-hx-on-load'))->toBeNull()
			->and(AttributeBag::normalizeAttributeName('hx-action'))->toBe('hx-action')
			->and(AttributeBag::normalizeAttributeName('hx-status:422'))->toBe('hx-status:422')
			->and(AttributeBag::normalizeAttributeName('data-onboarded'))->toBe('data-onboarded')
			->and(AttributeBag::normalizeAttributeName('_'))->toBe('_');

		$bag = new AttributeBag([
			'onclick' => 'alert(1)',
			'ONERROR' => 'alert(2)',
			'hx-on:click' => 'alert(3)',
			'hx-on--after-request' => 'alert(4)',
			'data-hx-on:click' => 'alert(5)',
			'data-hx-on-submit' => 'alert(6)',
			'hx-target' => '#panel',
			'data-onboarded' => 'yes',
		]);
		$bag->set('onload', 'alert(7)');
		$bag->hx('on:click', 'alert(8)');

		expect($bag->has('onclick'))->toBeFalse()
			->and($bag->has('hx-on:click'))->toBeFalse()
			->and($bag->has('data-hx-on:click'))->toBeFalse()
			->and($bag->has('onload'))->toBeFalse()
			->and($bag->render())->toBe('hx-target="#panel" data-onboarded="yes"');
	});
});

describe('SignedStateCodec, Context Binding, Sibling Arrays & Lifecycle Authorization', function (): void {
	it('enforces minimum 16-char salt entropy and derives domain-separated HKDF keys in SignedStateCodec', function (): void {
		expect(fn () => SignedStateCodec::deriveKey('short-salt', SignedStateCodec::CONTEXT_COMPONENT_STATE))
			->toThrow(WireException::class)
			->and(fn () => SignedStateCodec::deriveKey('   123456789012345   ', SignedStateCodec::CONTEXT_COMPONENT_STATE))
			->toThrow(WireException::class);

		$weakConfig = (object) ['userAuthSalt' => 'too-short-15chr'];
		expect(fn () => Component::resolveHmacSalt($weakConfig))->toThrow(WireException::class);

		$salt = 'strong-install-salt-at-least-32-bytes!';
		$stateKey = SignedStateCodec::deriveKey($salt, SignedStateCodec::CONTEXT_COMPONENT_STATE);
		$assetKey = SignedStateCodec::deriveKey($salt, SignedStateCodec::CONTEXT_ASSET_CONFIG);

		expect(strlen($stateKey))->toBe(32)
			->and(strlen($assetKey))->toBe(32)
			->and($stateKey)->not->toBe($assetKey);

		// Raw pre-HKDF HMAC signature using $salt directly MUST be rejected
		$encoded = base64_encode((string) json_encode([
			'__cmp' => HardeningSiblingAlphaComponent::class,
			'__expires' => time() + 3600,
		]));
		$legacyRawSig = hash_hmac('sha256', $encoded, $salt);
		expect(fn () => SignedStateCodec::decode($encoded . '|' . $legacyRawSig, $salt))
			->toThrow(WireException::class);

		$assetTokenA = SignedStateCodec::deriveAssetConfigToken($salt, 'csrf-token-alpha');
		$assetTokenB = SignedStateCodec::deriveAssetConfigToken($salt, 'csrf-token-beta');
		expect(strlen($assetTokenA))->toBe(32)
			->and(ctype_xdigit($assetTokenA))->toBeTrue()
			->and($assetTokenA)->not->toBe($assetTokenB);
	});

	it('rejects guest cross-session hx__state replay across distinct anonymous sessions', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->modules->get('Htmx');
		$htmx->init();
		$htmx->registerComponent('HardeningSiblingAlphaComponent', HardeningSiblingAlphaComponent::class);

		$users = wire()->users;
		$prevUser = wire()->user;
		$guest = $users->get('guest');

		try {
			$users->setCurrentUser($guest);
			SignedStateCodec::rotateSessionFingerprint(wire());

			$guestACmp = new HardeningSiblingAlphaComponent();
			$guestACmp->fill(['id' => 'guest-alpha', 'count' => 7]);
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $guestACmp->renderStatePayload(), $m);
			$guestASignedState = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

			// Same guest session A succeeds
			$sameSessionCmp = new HardeningSiblingAlphaComponent();
			wire()->input->get->hx__state = $guestASignedState;
			$sameSessionCmp->hydrate();
			expect($sameSessionCmp->count)->toBe(7);
			wire()->input->get->removeAll();

			// Rotate to Guest Session B (same guest user ID, different session fingerprint)
			SignedStateCodec::rotateSessionFingerprint(wire());

			$guestBCmp = new HardeningSiblingAlphaComponent();
			wire()->input->get->hx__state = $guestASignedState;
			expect(fn () => $guestBCmp->hydrate())->toThrow(WireException::class);

			// Replaying Guest A state to /hx/req in Guest Session B returns 403 bad_hmac
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			wire()->input->get->hx__state = $guestASignedState;
			wire()->input->get->__htmx_action = 'queryInspect';

			$resp = $htmx->handleEndpoint(new HookEvent());
			expect($resp)->toBe('Forbidden: Invalid HTMX State Signature.');
		} finally {
			wire()->input->get->removeAll();
			unset($_SERVER['HTTP_HX_REQUEST']);
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$users->setCurrentUser($prevUser);
		}
	});

	it('binds originating page (__pid) and admin context (__adm) across round-trips and blocks unauthorized replay', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->modules->get('Htmx');
		$htmx->init();
		$htmx->registerComponent('HardeningSiblingAlphaComponent', HardeningSiblingAlphaComponent::class);

		$pages = wire()->pages;
		$users = wire()->users;
		$prevUser = wire()->user;
		$prevPage = wire()->page;
		$prevProtectCsrf = wire()->config->protectCSRF;

		$home = $pages->get(1);
		$adminRoot = $pages->get((int) (wire()->config->adminRootPageID ?: 2));
		$guest = $users->get('guest');
		$superuser = $users->get('name=admin|superuser') ?: $users->get(41);
		$viewableHookId = null;

		try {
			wire()->config->protectCSRF = false;

			// 1. State signed in admin context (__adm = true) cannot be replayed if __uid matched or tampered context
			if ($superuser && $superuser->id) {
				$users->setCurrentUser($superuser);
				wire()->fuel->set('page', $adminRoot);
				SignedStateCodec::rotateSessionFingerprint(wire());

				$adminCmp = new HardeningSiblingAlphaComponent();
				$adminCmp->fill(['id' => 'adm-cmp', 'count' => 3]);
				preg_match('/name="hx__state"\s+value="([^"]+)"/', $adminCmp->renderStatePayload(), $mAdm);
				$adminState = html_entity_decode($mAdm[1], ENT_QUOTES, 'UTF-8');
				$decodedAdm = Component::decodeSignedPayload($adminState, $adminCmp->getHmacSalt());
				expect($decodedAdm['__adm'] ?? null)->toBeTrue();
			}

			// Guest user cannot replay an __adm=true state even with matching __uid and __sid (e.g. after privilege revocation)
			$users->setCurrentUser($guest);
			wire()->fuel->set('page', $home);
			SignedStateCodec::rotateSessionFingerprint(wire());
			$forgedGuestAdminCmp = new HardeningSiblingAlphaComponent();
			$guestAdminState = SignedStateCodec::encode(
				['id' => 'guest-adm', 'count' => 1],
				$forgedGuestAdminCmp->getHmacSalt(),
				wire(),
				24,
				HardeningSiblingAlphaComponent::class,
				'guest-adm',
				(int) $guest->id,
				$forgedGuestAdminCmp->requestUrl(),
				0,
				true
			);

			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			wire()->input->get->hx__state = $guestAdminState;
			wire()->input->get->__htmx_action = 'queryInspect';

			$admRejectResp = $htmx->handleEndpoint(new HookEvent());
			expect($admRejectResp)->toContain('Forbidden');
			expect(fn () => $htmx->renderComponent(HardeningSiblingAlphaComponent::class, ['id' => 'guest-adm']))
				->toThrow(WirePermissionException::class);
			wire()->input->get->removeAll();

			// 2. Originating frontend page (__pid = 1) preserved across /hx/req re-render and blocked if page becomes unviewable
			$users->setCurrentUser($guest);
			wire()->fuel->set('page', $home);
			SignedStateCodec::rotateSessionFingerprint(wire());

			$frontCmp = new HardeningSiblingAlphaComponent();
			$frontCmp->fill(['id' => 'front-cmp', 'count' => 5]);
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $frontCmp->renderStatePayload(), $mFront);
			$round1State = html_entity_decode($mFront[1], ENT_QUOTES, 'UTF-8');
			$decodedR1 = Component::decodeSignedPayload($round1State, $frontCmp->getHmacSalt());
			expect($decodedR1['__pid'] ?? null)->toBe(1)
				->and($decodedR1['__adm'] ?? null)->toBeFalse();

			// Simulate /hx/req where $wire->page is NullPage (id = 0): __pid=1 must be preserved in round-trip 2 output!
			wire()->fuel->set('page', $pages->newNullPage());
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			wire()->input->post->hx__state = $round1State;
			wire()->input->post->__htmx_action = 'bump';

			$round2Html = $htmx->handleEndpoint(new HookEvent());
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $round2Html, $mRound2);
			expect($mRound2)->not->toBeEmpty();
			$round2State = html_entity_decode($mRound2[1], ENT_QUOTES, 'UTF-8');
			$decodedR2 = Component::decodeSignedPayload($round2State, $frontCmp->getHmacSalt());
			expect($decodedR2['__pid'] ?? null)->toBe(1)
				->and($decodedR2['count'] ?? null)->toBe(6);

			// Now make page 1 unviewable: round2State must be rejected with 403 on handleEndpoint() and WirePermissionException on renderComponent()
			$viewableHookId = wire()->addHookAfter('Page::viewable', static function (HookEvent $e): void {
				/** @var Page $p */
				$p = $e->object;
				if ($p->id === 1) {
					$e->return = false;
				}
			});

			wire()->input->post->hx__state = $round2State;
			wire()->input->post->__htmx_action = 'bump';
			$blockedEndpointResp = $htmx->handleEndpoint(new HookEvent());
			expect($blockedEndpointResp)->toContain('Forbidden');

			expect(fn () => $htmx->renderComponent(HardeningSiblingAlphaComponent::class, ['id' => 'front-cmp']))
				->toThrow(WirePermissionException::class);
		} finally {
			if ($viewableHookId !== null) {
				wire()->removeHook($viewableHookId);
			}
			wire()->input->post->removeAll();
			wire()->input->get->removeAll();
			unset($_SERVER['HTTP_HX_REQUEST']);
			$_SERVER['REQUEST_METHOD'] = 'GET';
			wire()->config->protectCSRF = $prevProtectCsrf;
			wire()->fuel->set('page', $prevPage);
			$users->setCurrentUser($prevUser);
		}
	});

	it('handles multi-candidate hx__state[] sibling arrays without false tampering errors and returns 400 missing_state on unmatched HX-Target', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->modules->get('Htmx');
		$htmx->init();
		$htmx->registerComponent('HardeningSiblingAlphaComponent', HardeningSiblingAlphaComponent::class);
		$htmx->registerComponent('HardeningSiblingBetaComponent', HardeningSiblingBetaComponent::class);

		try {
			SignedStateCodec::rotateSessionFingerprint(wire());

			$alpha = new HardeningSiblingAlphaComponent();
			$alpha->fill(['id' => 'sibling-alpha', 'count' => 42]);
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $alpha->renderStatePayload(), $mA);
			$signedAlpha = html_entity_decode($mA[1], ENT_QUOTES, 'UTF-8');

			$beta = new HardeningSiblingBetaComponent();
			$beta->fill(['id' => 'sibling-beta', 'label' => 'second']);
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $beta->renderStatePayload(), $mB);
			$signedBeta = html_entity_decode($mB[1], ENT_QUOTES, 'UTF-8');

			// 1. Sibling component WITHOUT explicit ID in hx__state=[$signedAlpha] must NOT fall back to reset() and throw tampering exception
			wire()->input->post->set('hx__state', [$signedAlpha]);
			$unmatchedAutoIdBeta = new HardeningSiblingBetaComponent();
			$unmatchedAutoIdBeta->hydrate();
			expect($unmatchedAutoIdBeta->label)->toBe('initial');

			// 2. Sibling component WITH matching class in hx__state=[$signedAlpha, $signedBeta] hydrates its own state cleanly
			wire()->input->post->set('hx__state', [$signedAlpha, $signedBeta]);
			$matchedBeta = new HardeningSiblingBetaComponent();
			$matchedBeta->hydrate();
			expect($matchedBeta->label)->toBe('second')
				->and($matchedBeta->id)->toBe('sibling-beta');

			// 3. handleEndpoint() with HX-Target that matches NO candidate in hx__state[] MUST reject with 400 missing_state
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			$_SERVER['HTTP_HX_TARGET'] = '#missing-component-id';
			$htmx->init();
			$htmx->registerComponent('HardeningSiblingAlphaComponent', HardeningSiblingAlphaComponent::class);
			$htmx->registerComponent('HardeningSiblingBetaComponent', HardeningSiblingBetaComponent::class);
			wire()->input->post->set('hx__state', [$signedAlpha, $signedBeta]);
			wire()->input->post->__htmx_action = 'bump';

			$resp = $htmx->handleEndpoint(new HookEvent());
			expect($resp)->toBe('Bad Request: Missing HTMX State Payload.');
		} finally {
			wire()->input->post->removeAll();
			wire()->input->get->removeAll();
			unset($_SERVER['HTTP_HX_REQUEST'], $_SERVER['HTTP_HX_TARGET']);
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}
	});

	it('enforces authorize(), authorizeAction(), and renderReady() before executeAction() and rethrows security exceptions in renderComponent()', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->modules->get('Htmx');
		$htmx->init();
		$htmx->registerComponent('HardeningGuardedComponent', HardeningGuardedComponent::class);

		$prevProtectCsrf = wire()->config->protectCSRF;

		try {
			wire()->config->protectCSRF = false;
			SignedStateCodec::rotateSessionFingerprint(wire());

			HardeningGuardedComponent::resetFlags();
			$seed = new HardeningGuardedComponent();
			$seed->fill(['id' => 'guarded-1']);
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $seed->renderStatePayload(), $m);
			$signedState = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

			// 1. authorize() === false blocks BEFORE executeAction() and renderToString() in handleEndpoint() (403) and renderComponent() (WirePermissionException)
			HardeningGuardedComponent::$allowAuthorize = false;
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			wire()->input->post->hx__state = $signedState;
			wire()->input->post->__htmx_action = 'allowedMutate';

			$respAuthDenied = $htmx->handleEndpoint(new HookEvent());
			expect($respAuthDenied)->toContain('Forbidden')
				->and(HardeningGuardedComponent::$executedActions)->toBe([]);

			expect(fn () => $htmx->renderComponent(HardeningGuardedComponent::class, ['id' => 'guarded-1']))
				->toThrow(WirePermissionException::class);
			expect(HardeningGuardedComponent::$executedActions)->toBe([]);

			// 2. authorizeAction('forbiddenMutate') === false blocks BEFORE method executes, while 'allowedMutate' runs
			HardeningGuardedComponent::resetFlags();
			wire()->input->post->hx__state = $signedState;
			wire()->input->post->__htmx_action = 'forbiddenMutate';

			$respActionDenied = $htmx->handleEndpoint(new HookEvent());
			expect($respActionDenied)->toContain('Forbidden')
				->and(HardeningGuardedComponent::$executedActions)->toBe([]);

			expect(fn () => $htmx->renderComponent(HardeningGuardedComponent::class, ['id' => 'guarded-1']))
				->toThrow(WirePermissionException::class);
			expect(HardeningGuardedComponent::$executedActions)->toBe([]);

			// Lifecycle methods authorize / authorizeAction themselves cannot be invoked via __htmx_action
			wire()->input->post->__htmx_action = 'authorize';
			$cmpTryLifecycle = new HardeningGuardedComponent();
			$cmpTryLifecycle->setPreDecodedState(Component::decodeSignedPayload($signedState, $seed->getHmacSalt()));
			$cmpTryLifecycle->hydrate();
			expect($cmpTryLifecycle->executeAction())->toBeFalse();

			// Allowed action executes normally
			wire()->input->post->hx__state = $signedState;
			wire()->input->post->__htmx_action = 'allowedMutate';
			$okHtml = $htmx->handleEndpoint(new HookEvent());
			expect($okHtml)->toContain('count:1')
				->and(HardeningGuardedComponent::$executedActions)->toBe(['allowedMutate']);

			// 3. renderReady() === false prevents executeAction() from executing
			HardeningGuardedComponent::resetFlags();
			HardeningGuardedComponent::$isReady = false;
			wire()->input->post->hx__state = $signedState;
			wire()->input->post->__htmx_action = 'allowedMutate';

			$notReadyHtml = $htmx->handleEndpoint(new HookEvent());
			expect($notReadyHtml)->toBe('')
				->and(HardeningGuardedComponent::$executedActions)->toBe([]);

			// 4. renderComponent() rethrows WireCSRFException and logs unexpected RuntimeException
			HardeningGuardedComponent::resetFlags();
			wire()->input->post->removeAll();
			HardeningGuardedComponent::$throwCsrfInMount = true;
			expect(fn () => $htmx->renderComponent(HardeningGuardedComponent::class))
				->toThrow(WireCSRFException::class);

			HardeningGuardedComponent::resetFlags();
			HardeningGuardedComponent::$throwRuntimeInMount = true;
			$prevDebug = (bool) wire()->config->debug;
			wire()->config->debug = false;
			try {
				$fallbackOut = $htmx->renderComponent(HardeningGuardedComponent::class);
				expect($fallbackOut)->toBe('');
			} finally {
				wire()->config->debug = $prevDebug;
			}
		} finally {
			HardeningGuardedComponent::resetFlags();
			wire()->input->post->removeAll();
			wire()->input->get->removeAll();
			unset($_SERVER['HTTP_HX_REQUEST']);
			$_SERVER['REQUEST_METHOD'] = 'GET';
			wire()->config->protectCSRF = $prevProtectCsrf;
		}
	});
});

describe('Ui Wire Integration, Multi-Language Memo, HtmxCache Isolation, ConfinedViewRenderer & CLI', function (): void {
	it('wires Ui as ProcessWire\Wire, isolates multi-language memos/shapes, caches ephemeral closures, sanitizes dangerous tags, and hides UIKit error HTML', function (): void {
		$tempCacheDir = sys_get_temp_dir() . '/pw-htmx-ui-hardening-' . bin2hex(random_bytes(6));
		$cache = new HtmxCache($tempCacheDir, true, 32);
		wire()->wire($cache);
		$prevCache = wire('htmxCache');
		wire()->fuel->set('htmxCache', $cache);
		/** @var Htmx $htmx */
		$htmx = wire()->modules->get('Htmx');
		$prevModuleCache = $htmx->cache;
		$htmx->cache = $cache;

		$user = wire()->user;
		$prevLang = $user->language ?? null;
		$prevDebug = (bool) wire()->config->debug;

		$makeLang = static function (int $id): object {
			if (class_exists(\ProcessWire\Language::class)) {
				$lang = new \ProcessWire\Language();
				wire()->wire($lang);
				$lang->id = $id;
				return $lang;
			}
			return (object) ['id' => $id];
		};
		$langEn = $makeLang(1010);
		$langTr = $makeLang(1011);

		try {
			// 1. Ui extends ProcessWire\Wire and Ui::make() wires the booted ProcessWire instance
			$ui = HardeningLocaleBadgeUi::make(['code' => 'welcome']);
			expect($ui)->toBeInstanceOf(Wire::class)
				->and($ui->wire())->not->toBeNull();

			// 2. Multi-language isolation in Ui::memo(true, true) and buildShapeKeyValues()
			$user->language = $langEn;
			$enOut = (string) HardeningLocaleBadgeUi::make(['code' => 'welcome'])->memo(true, true);
			expect($enOut)->toContain('lang:1010:welcome');

			$user->language = $langTr;
			$trOut = (string) HardeningLocaleBadgeUi::make(['code' => 'welcome'])->memo(true, true);
			expect($trOut)->toContain('lang:1011:welcome')
				->and($trOut)->not->toBe($enOut);

			// Reload from disk after resetMemory: both languages stay isolated
			$cache->resetMemory();
			$user->language = $langEn;
			expect((string) HardeningLocaleBadgeUi::make(['code' => 'welcome'])->memo(true, true))
				->toContain('lang:1010:welcome');
			$user->language = $langTr;
			expect((string) HardeningLocaleBadgeUi::make(['code' => 'welcome'])->memo(true, true))
				->toContain('lang:1011:welcome');

			// 3. Static per-process closure cache when ->cache(false) is set
			HardeningEphemeralShapeUi::$compileCalls = 0;
			for ($i = 1; $i <= 5; $i++) {
				$ephemeralOut = (string) HardeningEphemeralShapeUi::make(['title' => "Item {$i}"])->cache(false);
				expect($ephemeralOut)->toContain("Item {$i}");
			}
			expect(HardeningEphemeralShapeUi::$compileCalls)->toBe(1)
				->and(is_dir($cache->getUiClassDir(HardeningEphemeralShapeUi::class)))->toBeFalse();

			// 4. Expanded sanitizeTagName() denylist
			foreach (['script', 'SCRIPT', 'style', 'iframe', 'object', 'embed', 'base', 'meta', 'link'] as $blockedTag) {
				expect(HardeningEphemeralShapeUi::exposeSanitizeTag($blockedTag))->toBe('div');
			}
			expect(HardeningEphemeralShapeUi::exposeSanitizeTag('article'))->toBe('article')
				->and(HardeningEphemeralShapeUi::exposeSanitizeTag('custom-widget'))->toBe('custom-widget');

			// 5. Ui::__toString() never leaks UIKit alert markup; emits HTML comment only in debug mode
			$crashingUi = new class extends Ui {
				public function render(): string
				{
					throw new \RuntimeException('Sensitive <detail>');
				}
			};
			wire()->config->debug = false;
			expect((string) $crashingUi)->toBe('');

			wire()->config->debug = true;
			$debugComment = (string) $crashingUi;
			expect($debugComment)->toStartWith('<!-- HTMX UI Rendering Error:')
				->and($debugComment)->toContain('Sensitive &lt;detail&gt;')
				->and($debugComment)->not->toContain('uk-alert');
		} finally {
			wire()->config->debug = $prevDebug;
			if ($prevLang !== null) {
				$user->language = $prevLang;
			}
			$htmx->cache = $prevModuleCache;
			if ($prevCache !== null) {
				wire()->fuel->set('htmxCache', $prevCache);
			}
			$cache->clear();
		}
	});

	it('prevents substring over-eviction in HtmxCache::clear() and isolates same-short-name classes across namespaces', function (): void {
		require_once dirname(__DIR__) . '/Support/NamespaceCollisionFixtures.php';

		$tempCacheDir = sys_get_temp_dir() . '/pw-htmx-cache-isolation-' . bin2hex(random_bytes(6));
		$cache = new HtmxCache($tempCacheDir, true, 32);
		wire()->wire($cache);
		$prevCache = wire('htmxCache');
		wire()->fuel->set('htmxCache', $cache);
		/** @var Htmx $htmx */
		$htmx = wire()->modules->get('Htmx');
		$prevModuleCache = $htmx->cache;
		$htmx->cache = $cache;

		try {
			// 1. Substring over-eviction check: clear('HardeningCard') must NOT evict HardeningCardHeader or HardeningProductCard
			expect((string) HardeningCard::make(['label' => 'A']))->toContain('card:A')
				->and((string) HardeningCardHeader::make(['label' => 'B']))->toContain('header:B')
				->and((string) HardeningProductCard::make(['label' => 'C']))->toContain('product:C');

			$cardDir = $cache->getUiClassDir(HardeningCard::class);
			$headerDir = $cache->getUiClassDir(HardeningCardHeader::class);
			$productDir = $cache->getUiClassDir(HardeningProductCard::class);

			expect(is_dir($cardDir))->toBeTrue()
				->and(is_dir($headerDir))->toBeTrue()
				->and(is_dir($productDir))->toBeTrue();

			$deleted = $cache->clear('HardeningCard');
			expect($deleted)->toBeGreaterThanOrEqual(1)
				->and(is_dir($cardDir))->toBeFalse()
				->and(is_dir($headerDir))->toBeTrue()
				->and(is_dir($productDir))->toBeTrue();

			// Memory closures for HardeningCardHeader and HardeningProductCard remain warm
			HardeningCardHeader::$compileCalls = 0;
			HardeningProductCard::$compileCalls = 0;
			expect((string) HardeningCardHeader::make(['label' => 'B2']))->toContain('header:B2')
				->and((string) HardeningProductCard::make(['label' => 'C2']))->toContain('product:C2')
				->and(HardeningCardHeader::$compileCalls)->toBe(0)
				->and(HardeningProductCard::$compileCalls)->toBe(0);

			// 2. Namespace folder collision check: NsAlpha\SharedWidgetUi vs NsBeta\SharedWidgetUi
			$alphaOut = (string) \Totoglu\Htmx\Tests\NsAlpha\SharedWidgetUi::make()->addChild('Alpha');
			$betaOut = (string) \Totoglu\Htmx\Tests\NsBeta\SharedWidgetUi::make()->addChild('Beta');
			expect($alphaOut)->toContain('ns-alpha-alpha')
				->and($betaOut)->toContain('ns-beta-beta');

			$alphaDir = $cache->getUiClassDir(\Totoglu\Htmx\Tests\NsAlpha\SharedWidgetUi::class);
			$betaDir = $cache->getUiClassDir(\Totoglu\Htmx\Tests\NsBeta\SharedWidgetUi::class);
			expect($alphaDir)->not->toBe($betaDir)
				->and(is_dir($alphaDir))->toBeTrue()
				->and(is_dir($betaDir))->toBeTrue();

			// Clearing FQCN NsAlpha\SharedWidgetUi leaves NsBeta\SharedWidgetUi intact
			$cache->clear(\Totoglu\Htmx\Tests\NsAlpha\SharedWidgetUi::class);
			expect(is_dir($alphaDir))->toBeFalse()
				->and(is_dir($betaDir))->toBeTrue();
		} finally {
			$htmx->cache = $prevModuleCache;
			if ($prevCache !== null) {
				wire()->fuel->set('htmxCache', $prevCache);
			}
			$cache->clear();
		}
	});

	it('unifies confined view rendering across ConfinedViewRenderer, Component::render(), and Fragment::renderFile()', function (): void {
		$tplPath = rtrim((string) wire()->config->paths->templates, '/') . '/';
		$tmpViewRel = '_htmx_test_confined_' . bin2hex(random_bytes(6)) . '.php';
		$tmpViewAbs = $tplPath . $tmpViewRel;

		file_put_contents(
			$tmpViewAbs,
			"<?php\ndeclare(strict_types=1);\necho 'safe:' . htmlspecialchars((string) (\$greeting ?? ''), ENT_QUOTES, 'UTF-8') . '|cmp:' . (isset(\$component) && is_object(\$component) ? 'yes' : 'no');\n"
		);

		try {
			$frag = new Fragment();
			wire()->wire($frag);

			$fragHtml = $frag->renderFile($tmpViewRel, [
				'greeting' => 'Hello',
				'_SERVER' => ['INJECTED' => 'nope'],
				'wire' => 'hijack',
			]);
			expect($fragHtml)->toBe('safe:Hello|cmp:no');

			$cmp = new class extends Component {
				public string $greeting = 'FromComponent';
			};
			$cmp->setView($tmpViewRel);
			expect($cmp->render())->toBe('safe:FromComponent|cmp:yes');

			// Direct ConfinedViewRenderer blocks stream wrappers, backslashes, NUL bytes, and escapes
			expect(ConfinedViewRenderer::render(wire(), 'php://filter/resource=' . $tmpViewRel))->toBe('')
				->and(ConfinedViewRenderer::render(wire(), '..\\' . $tmpViewRel))->toBe('')
				->and(ConfinedViewRenderer::render(wire(), $tmpViewRel . "\0.php"))->toBe('')
				->and(ConfinedViewRenderer::render(wire(), '../../wire/config.php'))->toBe('');
		} finally {
			@unlink($tmpViewAbs);
		}
	});

	it('scaffolds nested Components and Ui elements via MakeHtmxComponentCommand and MakeHtmxUiCommand while blocking unsafe paths', function (): void {
		$sitePath = rtrim((string) wire()->config->paths->site, '/');
		$relDir = '_htmx_scaffold_test_' . bin2hex(random_bytes(6));
		$absDir = $sitePath . '/' . $relDir;

		try {
			// 1. MakeHtmxComponentCommand happy path + duplicate failure + forbidden dirs
			$cmpCmd = new MakeHtmxComponentCommand();
			$cmpTester = new CommandTester($cmpCmd);

			$statusCmp = $cmpTester->execute([
				'name' => 'Dashboard/LiveCounter',
				'--dir' => $relDir . '/components',
			]);
			$generatedCmpFile = $absDir . '/components/Dashboard/LiveCounter.php';
			expect($statusCmp)->toBe(Command::SUCCESS)
				->and(is_file($generatedCmpFile))->toBeTrue();

			$cmpSource = (string) file_get_contents($generatedCmpFile);
			expect($cmpSource)->toContain('declare(strict_types=1);')
				->and($cmpSource)->toContain('namespace Htmx\\Component\\Dashboard;')
				->and($cmpSource)->toContain('class LiveCounter extends Component');

			// Duplicate file must fail cleanly
			expect($cmpTester->execute([
				'name' => 'Dashboard/LiveCounter',
				'--dir' => $relDir . '/components',
			]))->toBe(Command::FAILURE);

			// Forbidden site/assets and site/modules targets must fail
			expect($cmpTester->execute([
				'name' => 'BadAssetCmp',
				'--dir' => 'assets/htmx',
			]))->toBe(Command::FAILURE)
				->and($cmpTester->execute([
					'name' => 'BadModuleCmp',
					'--dir' => 'modules/Htmx',
				]))->toBe(Command::FAILURE);

			// 2. MakeHtmxUiCommand happy path
			$uiCmd = new MakeHtmxUiCommand();
			$uiTester = new CommandTester($uiCmd);

			$statusUi = $uiTester->execute([
				'name' => 'Cards/MetricTile',
				'--dir' => $relDir . '/ui',
			]);
			$generatedUiFile = $absDir . '/ui/Cards/MetricTile.php';
			expect($statusUi)->toBe(Command::SUCCESS)
				->and(is_file($generatedUiFile))->toBeTrue();

			$uiSource = (string) file_get_contents($generatedUiFile);
			expect($uiSource)->toContain('declare(strict_types=1);')
				->and($uiSource)->toContain('namespace Htmx\\Ui\\Cards;')
				->and($uiSource)->toContain('class MetricTile extends Ui')
				->and($uiSource)->toContain("public string \$name = 'metric-tile';");
		} finally {
			if (is_dir($absDir)) {
				wire()->files->rmdir($absDir, true);
			}
		}
	});

	it('executes a full multi-round-trip /hx/req handleEndpoint() component lifecycle end-to-end', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->modules->get('Htmx');
		$htmx->init();
		$htmx->registerComponent('HardeningRoundTripCartComponent', HardeningRoundTripCartComponent::class);

		$prevProtectCsrf = wire()->config->protectCSRF;

		try {
			wire()->config->protectCSRF = false;
			SignedStateCodec::rotateSessionFingerprint(wire());

			// Step 1: Initial SSR render via renderComponent()
			$initialHtml = $htmx->renderComponent(HardeningRoundTripCartComponent::class, [
				'id' => 'cart-widget',
				'items' => 1,
			]);
			expect($initialHtml)->toContain('items:1');
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $initialHtml, $m0);
			$state0 = html_entity_decode($m0[1], ENT_QUOTES, 'UTF-8');

			// Step 2: Round-trip 1 POST mutation (addItem qty=2) -> updates state, queues <hx-partial>, emits HX-Trigger
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			$_SERVER['HTTP_HX_TARGET'] = 'div#cart-widget';
			$htmx->init();
			$htmx->registerComponent('HardeningRoundTripCartComponent', HardeningRoundTripCartComponent::class);
			wire()->input->post->hx__state = $state0;
			wire()->input->post->__htmx_action = 'addItem';
			wire()->input->post->qty = '2';

			$round1Html = $htmx->handleEndpoint(new HookEvent());
			expect($round1Html)->toContain('items:3')
				->and($round1Html)->toContain('<hx-partial hx-target="#cart-badge" hx-swap="outerMorph">')
				->and($round1Html)->toContain('<span id="cart-badge">3</span>');

			$triggerHeader = json_decode((string) $htmx->response->getHeader('HX-Trigger'), true);
			expect($triggerHeader['cart:updated']['items'] ?? null)->toBe(3);

			preg_match('/name="hx__state"\s+value="([^"]+)"/', $round1Html, $m1);
			$state1 = html_entity_decode($m1[1], ENT_QUOTES, 'UTF-8');

			// Step 3: Round-trip 2 POST mutation using freshly signed state from Round 1
			wire()->input->post->removeAll();
			$htmx->init();
			$htmx->registerComponent('HardeningRoundTripCartComponent', HardeningRoundTripCartComponent::class);
			wire()->input->post->hx__state = $state1;
			wire()->input->post->__htmx_action = 'addItem';
			wire()->input->post->qty = '4';

			$round2Html = $htmx->handleEndpoint(new HookEvent());
			expect($round2Html)->toContain('items:7');

			preg_match('/name="hx__state"\s+value="([^"]+)"/', $round2Html, $m2);
			$state2 = html_entity_decode($m2[1], ENT_QUOTES, 'UTF-8');

			// Step 4: Round-trip 3 GET query* read-only inspection using Round 2's signed state
			wire()->input->post->removeAll();
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$htmx->init();
			$htmx->registerComponent('HardeningRoundTripCartComponent', HardeningRoundTripCartComponent::class);
			wire()->input->get->hx__state = $state2;
			wire()->input->get->__htmx_action = 'queryTotalBadge';

			$round3Html = $htmx->handleEndpoint(new HookEvent());
			expect($round3Html)->toBe('<strong>Total: 7</strong>');
		} finally {
			wire()->input->post->removeAll();
			wire()->input->get->removeAll();
			unset($_SERVER['HTTP_HX_REQUEST'], $_SERVER['HTTP_HX_TARGET']);
			$_SERVER['REQUEST_METHOD'] = 'GET';
			wire()->config->protectCSRF = $prevProtectCsrf;
		}
	});
});

class HardeningSiblingAlphaComponent extends Component
{
	public int $count = 0;

	public function bump(): void
	{
		$this->count++;
	}

	public function queryInspect(): string
	{
		return '<div id="' . htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8') . '">alpha:' . $this->count . $this->renderStatePayload() . '</div>';
	}

	public function render(): string
	{
		return '<div id="' . htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8') . '">alpha:' . $this->count . $this->renderStatePayload() . '</div>';
	}
}

class HardeningSiblingBetaComponent extends Component
{
	public string $label = 'initial';

	public function render(): string
	{
		return '<div id="' . htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8') . '">beta:' . htmlspecialchars($this->label, ENT_QUOTES, 'UTF-8') . $this->renderStatePayload() . '</div>';
	}
}

class HardeningGuardedComponent extends Component
{
	public static bool $allowAuthorize = true;
	public static bool $isReady = true;
	public static bool $throwCsrfInMount = false;
	public static bool $throwRuntimeInMount = false;
	/** @var list<string> */
	public static array $executedActions = [];

	public int $count = 0;

	public static function resetFlags(): void
	{
		self::$allowAuthorize = true;
		self::$isReady = true;
		self::$throwCsrfInMount = false;
		self::$throwRuntimeInMount = false;
		self::$executedActions = [];
	}

	public function mount(): void
	{
		if (self::$throwCsrfInMount) {
			throw new WireCSRFException('Simulated CSRF failure.');
		}
		if (self::$throwRuntimeInMount) {
			throw new \RuntimeException('Simulated unexpected component crash.');
		}
	}

	public function authorize(): bool
	{
		return self::$allowAuthorize;
	}

	public function authorizeAction(string $action): bool
	{
		return $action !== 'forbiddenMutate';
	}

	public function renderReady(): bool
	{
		return self::$isReady;
	}

	public function allowedMutate(): void
	{
		self::$executedActions[] = 'allowedMutate';
		$this->count++;
	}

	public function forbiddenMutate(): void
	{
		self::$executedActions[] = 'forbiddenMutate';
		$this->count += 99;
	}

	public function render(): string
	{
		return '<div id="' . htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8') . '">count:' . $this->count . $this->renderStatePayload() . '</div>';
	}
}

class HardeningLocaleBadgeUi extends Ui
{
	public array $shapeParams = ['variant' => 'default'];
	public array $slotParams = ['code' => ''];

	protected function compileTemplate(array $shape): string
	{
		$langId = (int) ($this->wire()?->user?->language?->id ?? 0);
		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\treturn '<span' . (\$attrs['root'] ?? '') . '>lang:{$langId}:' . (\$s['code'] ?? '') . '</span>';\n"
			. "}";
	}
}

class HardeningEphemeralShapeUi extends Ui
{
	public static int $compileCalls = 0;
	public array $shapeParams = ['variant' => 'compact'];
	public array $slotParams = ['title' => ''];

	public static function exposeSanitizeTag(string $tag): string
	{
		return self::sanitizeTagName($tag);
	}

	protected function compileTemplate(array $shape): string
	{
		self::$compileCalls++;
		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\treturn '<div' . (\$attrs['root'] ?? '') . '>' . (\$s['title'] ?? '') . '</div>';\n"
			. "}";
	}
}

class HardeningCard extends Ui
{
	public static int $compileCalls = 0;
	public array $slotParams = ['label' => ''];

	protected function compileTemplate(array $shape): string
	{
		self::$compileCalls++;
		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\treturn '<div>card:' . (\$s['label'] ?? '') . '</div>';\n"
			. "}";
	}
}

class HardeningCardHeader extends Ui
{
	public static int $compileCalls = 0;
	public array $slotParams = ['label' => ''];

	protected function compileTemplate(array $shape): string
	{
		self::$compileCalls++;
		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\treturn '<header>header:' . (\$s['label'] ?? '') . '</header>';\n"
			. "}";
	}
}

class HardeningProductCard extends Ui
{
	public static int $compileCalls = 0;
	public array $slotParams = ['label' => ''];

	protected function compileTemplate(array $shape): string
	{
		self::$compileCalls++;
		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\treturn '<article>product:' . (\$s['label'] ?? '') . '</article>';\n"
			. "}";
	}
}

class HardeningRoundTripCartComponent extends Component
{
	public int $items = 0;

	public function addItem(int $qty = 1): void
	{
		$this->items += max(1, $qty);
		$this->htmx?->response->trigger('cart:updated', ['items' => $this->items]);
		$this->htmx?->fragment->addPartial(
			'#cart-badge',
			'<span id="cart-badge">' . $this->items . '</span>',
			'outerMorph'
		);
	}

	public function queryTotalBadge(): string
	{
		return '<strong>Total: ' . $this->items . '</strong>';
	}

	public function render(): string
	{
		$safeId = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');
		return '<div id="' . $safeId . '">items:' . $this->items . $this->renderStatePayload() . '</div>';
	}
}
