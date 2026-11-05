<?php

declare(strict_types=1);

use ProcessWire\HookEvent;
use ProcessWire\Htmx;
use ProcessWire\WireException;
use Totoglu\Htmx\Bag\AttributeBag;
use Totoglu\Htmx\Bag\ParameterBag;
use Totoglu\Htmx\Component;
use Totoglu\Htmx\Fragment;
use Totoglu\Htmx\Request;
use Totoglu\Htmx\Response;
use Totoglu\Htmx\Ui;
use function ProcessWire\wire;

if (!class_exists('HtmxV4TestDummyComponent')) {
	class HtmxV4TestDummyComponent extends Component
	{
		public int $count = 0;
		public string $label = 'initial';
		public bool $flag = false;

		public function increment(int $step = 1, string $label = 'updated'): void
		{
			$this->count += $step;
			$this->label = $label;
		}

		public function querySummary(string $prefix = 'Total'): string
		{
			return "<span class='summary'>{$prefix}: {$this->count} ({$this->label})</span>";
		}

		public function queryLoadAssets(): void
		{
			$config = $this->wire()->config;
			if ($config) {
				$config->styles->add('/site/modules/Dummy/DummyViewport.css?v=1');
				$config->scripts->add('/site/modules/Dummy/DummyViewport.js?v=1');
				$config->js('dummyViewportSetting', ['active' => true]);
			}
		}

		public function render(): string
		{
			$id = 'hxc_' . $this->id();
			return "<div id='{$id}' data-count='{$this->count}' data-label='{$this->label}'>{$this->renderStatePayload()}</div>";
		}
	}
}

if (!class_exists('HtmxV4TestDummyUi')) {
	class HtmxV4TestDummyUi extends Ui
	{
		public array $defaultParams = [
			'tag' => 'button',
			'label' => 'Save',
		];

		public function render(): string
		{
			$tag = $this->parameters->getString('tag', 'button');
			$label = $this->parameters->getString('label', 'Save');
			return "<{$tag} {$this->attributes->render()}>{$label}</{$tag}>";
		}
	}
}

if (!class_exists('HtmxV4TestSiblingComponent')) {
	class HtmxV4TestSiblingComponent extends Component
	{
		public string $title = 'sibling-default';

		public function render(): string
		{
			return "<aside data-title='{$this->title}'>{$this->renderStatePayload()}</aside>";
		}
	}
}

if (!function_exists('resetHtmxTestState')) {
	function resetHtmxTestState(): void
	{
		foreach (array_keys($_SERVER) as $k) {
			if (str_starts_with((string) $k, 'HTTP_HX_')) {
				unset($_SERVER[$k]);
			}
		}
		$_POST = [];
		$_GET = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wire()->input->post->removeAll();
		wire()->input->get->removeAll();

		/** @var Htmx|null $htmx */
		$htmx = wire()->htmx;
		if ($htmx) {
			$htmx->response->reset();
			$htmx->fragment->clear();
		}
	}
}

beforeEach(function (): void {
	resetHtmxTestState();
});

afterEach(function (): void {
	resetHtmxTestState();
});

describe('Totoglu\Htmx\Request (HTMX v4)', function (): void {
	it('parses HTMX v4 request flags, requestType, targetTag, sourceTag, triggerName, and headers()', function (): void {
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_SERVER['HTTP_HX_BOOSTED'] = 'false';
		$_SERVER['HTTP_HX_HISTORY_RESTORE_REQUEST'] = 'true';
		$_SERVER['HTTP_HX_REQUEST_TYPE'] = 'partial';
		$_SERVER['HTTP_HX_TARGET'] = 'div#main-panel';
		$_SERVER['HTTP_HX_SOURCE'] = 'button#save-btn';
		$_SERVER['HTTP_HX_PROMPT'] = 'confirm-delete';
		$_SERVER['HTTP_HX_CURRENT_URL'] = 'https://example.test/items';
		// Legacy v2 headers must be ignored
		$_SERVER['HTTP_HX_TRIGGER'] = 'legacy-trigger-id';
		$_SERVER['HTTP_HX_TRIGGER_NAME'] = 'legacy-trigger-name';

		$req = new Request();

		expect($req->isHtmx())->toBeTrue()
			->and($req->isBoosted())->toBeFalse()
			->and($req->isHistoryRestore())->toBeTrue()
			->and($req->historyRestore())->toBeTrue()
			->and($req->requestType())->toBe('partial')
			->and($req->isPartial())->toBeTrue()
			->and($req->isFullPage())->toBeFalse()
			->and($req->rawTarget())->toBe('div#main-panel')
			->and($req->targetTag())->toBe('div')
			->and($req->target())->toBe('main-panel')
			->and($req->rawSource())->toBe('button#save-btn')
			->and($req->source())->toBe('button#save-btn')
			->and($req->sourceTag())->toBe('button')
			->and($req->trigger())->toBe('save-btn')
			->and($req->triggerName())->toBe('save-btn')
			->and($req->prompt())->toBe('confirm-delete')
			->and($req->currentUrl())->toBe('https://example.test/items');

		$headers = $req->headers();
		expect($headers)->toBeArray()
			->and($headers['request'])->toBe('true')
			->and($headers['request-type'])->toBe('partial')
			->and($headers['target'])->toBe('div#main-panel')
			->and($headers['source'])->toBe('button#save-btn');
	});

	it('falls back to __htmx_action in triggerName() when HX-Source has no id', function (): void {
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_SERVER['HTTP_HX_SOURCE'] = 'button';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		wire()->input->post->__htmx_action = 'publishItem';

		$req = new Request();

		expect($req->sourceTag())->toBe('button')
			->and($req->trigger())->toBeNull()
			->and($req->triggerName())->toBe('publishItem');
	});
});

describe('Totoglu\Htmx\Response (HTMX v4)', function (): void {
	it('sets HTMX v4 response headers, 2-arg location(), validationError(), and merges deferred triggers into single HX-Trigger', function (): void {
		$resp = new Response();

		$resp->location('/items', ['target' => '#list', 'swap' => 'outerMorph'])
			->retarget('#form-box')
			->reselect('#errors')
			->reswap('innerMorph')
			->trigger('saved', ['id' => 42])
			->triggerAfterSwap('toast:show', ['msg' => 'OK'])
			->triggerAfterSettle('anim:done', ['step' => 2])
			->setTriggers([
				'directEvent' => ['a' => 1],
				'swapSuffixEvent:swap' => ['b' => 2],
				'settleSuffixEvent:settle' => ['c' => 3],
			]);

		$resp->validationError('#err-target', '#err-select', 422);
		$resp->applyHeaders();

		expect($resp->getStatusCode())->toBe(422)
			->and($resp->getHeader('HX-Retarget'))->toBe('#err-target')
			->and($resp->getHeader('HX-Reselect'))->toBe('#err-select')
			->and($resp->getHeader('HX-Reswap'))->toBe('innerMorph');

		$locJson = json_decode((string) $resp->getHeader('HX-Location'), true);
		expect($locJson)->toBe([
			'path' => '/items',
			'target' => '#list',
			'swap' => 'outerMorph',
		]);

		$triggerJson = json_decode((string) $resp->getHeader('HX-Trigger'), true);
		expect($triggerJson)->toBeArray()
			->and($triggerJson['saved'])->toBe(['id' => 42])
			->and($triggerJson['directEvent'])->toBe(['a' => 1])
			->and($triggerJson['__pwHtmxAfterSwap']['events']['toast:show'])->toBe(['msg' => 'OK'])
			->and($triggerJson['__pwHtmxAfterSwap']['events']['swapSuffixEvent'])->toBe(['b' => 2])
			->and($triggerJson['__pwHtmxAfterSettle']['events']['anim:done'])->toBe(['step' => 2])
			->and($triggerJson['__pwHtmxAfterSettle']['events']['settleSuffixEvent'])->toBe(['c' => 3]);
	});
});

describe('Totoglu\Htmx\Fragment (HTMX v4)', function (): void {
	it('renders <hx-partial> elements via addPartial() with named swap parameter and validates all v4 swap styles', function (): void {
		$frag = new Fragment();

		$frag->addPartial(
			target: '#toast-container',
			html: '<div class="toast">Saved!</div>',
			swap: 'outerMorph',
			select: '.toast'
		);
		$frag->addOobSwap('#counter', '<span id="counter">9</span>', 'innerHTML');
		$frag->addOobSwap('#sync-box', '<div id="sync-box">Sync</div>', 'outerSync');

		$combined = $frag->getOobSwaps('<main>Primary</main>');

		expect($combined)->toContain('<main>Primary</main>')
			->and($combined)->toContain('<hx-partial hx-target="#toast-container" hx-swap="outerMorph" hx-select=".toast">' . "\n" . '<div class="toast">Saved!</div>' . "\n" . '</hx-partial>')
			->and($combined)->toContain('<span id="counter" hx-swap-oob="innerHTML">9</span>')
			->and($combined)->toContain('<div id="sync-box" hx-swap-oob="outerSync">Sync</div>');
	});
});

describe('Totoglu\Htmx\Ui & Bags (HTMX v4)', function (): void {
	it('supports all HTMX v4 fluent helpers and removes deprecated v2 htmx() method', function (): void {
		expect(method_exists(Ui::class, 'htmx'))->toBeFalse();

		$btn = HtmxV4TestDummyUi::make(['label' => 'Submit'])
			->action('/hx/req')
			->method('post')
			->query('/hx/search')
			->config(['history' => false])
			->ignore()
			->disable('self')
			->validate()
			->status(422, 'swap:outerMorph target:#errors')
			->actionTrigger('increment')
			->target('#box')
			->swap('outerMorph')
			->select('#inner')
			->selectOob('#oob')
			->vals(['step' => 2])
			->headers(['X-Custom' => '1'])
			->boost()
			->confirm('Proceed?')
			->prompt('Reason?')
			->indicator('#spin')
			->include('#extra')
			->params('*')
			->trigger('click')
			->sync('#form:abort')
			->encoding('multipart/form-data')
			->pushUrl('/new-url')
			->replaceUrl(true)
			->preserve();

		$html = $btn->render();

		expect($html)->toContain('hx-action="/hx/req"')
			->and($html)->toContain('hx-method="post"')
			->and($html)->toContain('hx-query="/hx/search"')
			->and($html)->toContain('hx-ignore')
			->and($html)->toContain('hx-disable="self"')
			->and($html)->toContain('hx-validate')
			->and($html)->toContain('hx-status:422="swap:outerMorph target:#errors"')
			->and($html)->toContain('type="submit"')
			->and($html)->toContain('name="__htmx_action"')
			->and($html)->toContain('value="increment"')
			->and($html)->toContain('hx-target="#box"')
			->and($html)->toContain('hx-swap="outerMorph"')
			->and($html)->toContain('hx-select="#inner"')
			->and($html)->toContain('hx-select-oob="#oob"')
			->and($html)->toContain('hx-boost="true"')
			->and($html)->toContain('hx-confirm="Proceed?"')
			->and($html)->toContain('hx-prompt="Reason?"')
			->and($html)->toContain('hx-indicator="#spin"')
			->and($html)->toContain('hx-include="#extra"')
			->and($html)->toContain('hx-params="*"')
			->and($html)->toContain('hx-trigger="click"')
			->and($html)->toContain('hx-sync="#form:abort"')
			->and($html)->toContain('enctype="multipart/form-data"')
			->and($html)->toContain('hx-push-url="/new-url"')
			->and($html)->toContain('hx-replace-url="true"')
			->and($html)->toContain('hx-preserve');
	});

	it('manages ParameterBag and AttributeBag classes and types cleanly', function (): void {
		$bag = new AttributeBag(['id' => 'card-1', 'data-count' => 5]);
		$bag->addClass('uk-card uk-card-default')
			->removeClass('uk-card-default')
			->toggleClass('uk-card-primary', true);

		expect($bag->hasClass('uk-card'))->toBeTrue()
			->and($bag->hasClass('uk-card-default'))->toBeFalse()
			->and($bag->hasClass('uk-card-primary'))->toBeTrue()
			->and($bag->getInt('data-count'))->toBe(5)
			->and((string) $bag)->toContain('id="card-1"');

		require_once dirname(__DIR__) . '/ui/TestAmnesiacBadge.php';
		$badgeHtml = \Htmx\Ui\TestAmnesiacBadge::make(['type' => 'success'])->render();
		expect($badgeHtml)->toContain('Unknown Attribute')
			->and($badgeHtml)->toContain('bg-green-100');
	});
});

describe('Totoglu\Htmx\Component & Htmx::handleEndpoint (HTMX v4)', function (): void {
	it('dehydrates, signs, hydrates, and executes GET query* actions and POST __htmx_action mutations', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->htmx;
		$htmx->registerComponent('DummyCounter', HtmxV4TestDummyComponent::class);

		$cmp = new HtmxV4TestDummyComponent();
		$cmp->fill(['count' => '7', 'label' => 'start', 'flag' => '1']);
		expect($cmp->count)->toBe(7)
			->and($cmp->label)->toBe('start')
			->and($cmp->flag)->toBeTrue();

		$stateInputHtml = $cmp->renderStatePayload();
		preg_match('/name="hx__state"\s+value="([^"]+)"/', $stateInputHtml, $m);
		expect($m)->toHaveKey(1);
		$signedPayload = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

		// 1. Test GET query* action via handleEndpoint
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wire()->input->get->hx__state = $signedPayload;
		wire()->input->get->__htmx_action = 'querySummary';
		wire()->input->get->prefix = 'Snapshot';

		$event = new HookEvent();
		$out = $htmx->handleEndpoint($event);
		expect($out)->toBe("<span class='summary'>Snapshot: 7 (start)</span>");

		// 2. Test GET non-query action is rejected (no mutation occurred)
		wire()->input->get->__htmx_action = 'increment';
		wire()->input->get->step = '5';
		$outRejected = $htmx->handleEndpoint($event);
		expect($outRejected)->toContain('data-count=\'7\'');
	});

	it('returns proper HTTP status codes for missing, malformed, tampered, and expired state payloads', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->htmx;
		$htmx->registerComponent('DummyCounter', HtmxV4TestDummyComponent::class);
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$event = new HookEvent();

		// Missing state -> 400
		$resMissing = $htmx->handleEndpoint($event);
		expect($resMissing)->toContain('Missing HTMX State Payload');

		// Malformed state -> 400
		wire()->input->get->hx__state = 'not-a-valid-payload';
		$resMalformed = $htmx->handleEndpoint($event);
		expect($resMalformed)->toContain('Malformed HTMX State Payload');

		// Tampered HMAC -> 403
		wire()->input->get->hx__state = base64_encode('{"__cmp":"HtmxV4TestDummyComponent"}') . '|badhash';
		$resBadHmac = $htmx->handleEndpoint($event);
		expect($resBadHmac)->toContain('Invalid HTMX State Signature');

		// Expired state -> 419
		$cmp = new HtmxV4TestDummyComponent();
		$expiredStateHtml = $cmp->renderStatePayload(-1);
		preg_match('/name="hx__state"\s+value="([^"]+)"/', $expiredStateHtml, $m);
		wire()->input->get->hx__state = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
		$resExpired = $htmx->handleEndpoint($event);
		expect($resExpired)->toContain('HTMX State Payload Expired');
	});

	it('isolates sibling components and multi-instance IDs in renderComponent() when hx__state is present', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->htmx;

		$cmpA = new HtmxV4TestDummyComponent();
		$cmpA->fill(['id' => 'alpha', 'count' => 12, 'label' => 'alpha-state']);
		preg_match('/name="hx__state"\s+value="([^"]+)"/', $cmpA->renderStatePayload(), $m);
		$signedAlpha = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wire()->input->get->hx__state = $signedAlpha;

		// Sibling of different class must render cleanly without hydration error
		$siblingOut = $htmx->renderComponent(HtmxV4TestSiblingComponent::class, ['title' => 'safe-sibling']);
		expect($siblingOut)->toContain("data-title='safe-sibling'");

		// Same class with different explicit id must not overwrite its props from alpha's hx__state
		$betaOut = $htmx->renderComponent(HtmxV4TestDummyComponent::class, ['id' => 'beta', 'count' => 1, 'label' => 'beta-init']);
		expect($betaOut)->toContain("data-count='1'")
			->and($betaOut)->toContain("data-label='beta-init'");

		// Matching class and explicit id hydrates alpha's state
		$alphaOut = $htmx->renderComponent(HtmxV4TestDummyComponent::class, ['id' => 'alpha', 'count' => 0, 'label' => 'alpha-init']);
		expect($alphaOut)->toContain("data-count='12'")
			->and($alphaOut)->toContain("data-label='alpha-state'");

		// Multi-instance hx__state[] array in handleEndpoint() matches target __id
		$cmpB = new HtmxV4TestDummyComponent();
		$cmpB->fill(['id' => 'beta', 'count' => 99, 'label' => 'beta-state']);
		preg_match('/name="hx__state"\s+value="([^"]+)"/', $cmpB->renderStatePayload(), $mB);
		$signedBeta = html_entity_decode($mB[1], ENT_QUOTES, 'UTF-8');

		$_SERVER['HTTP_HX_TARGET'] = '#beta';
		wire()->input->get->hx__state = [$signedAlpha, $signedBeta];
		$event = new HookEvent();
		$endpointOut = $htmx->handleEndpoint($event);
		expect($endpointOut)->toContain("data-count='99'")
			->and($endpointOut)->toContain("data-label='beta-state'");
		unset($_SERVER['HTTP_HX_TARGET']);
	});

	it('captures newly added styles, scripts, and jsConfig during handleEndpoint() and injects data-pw-htmx-config inside root element', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->htmx;
		$htmx->registerComponent('DummyCounter', HtmxV4TestDummyComponent::class);

		$cmp = new HtmxV4TestDummyComponent();
		$cmp->fill(['id' => 'asset-test', 'count' => 3, 'label' => 'assets']);
		preg_match('/name="hx__state"\s+value="([^"]+)"/', $cmp->renderStatePayload(), $m);
		$signedState = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wire()->input->get->hx__state = $signedState;
		wire()->input->get->__htmx_action = 'queryLoadAssets';

		$event = new HookEvent();
		$out = $htmx->handleEndpoint($event);

		expect($out)
			->toContain('data-pw-htmx-config')
			->toContain('DummyViewport.css?v=1')
			->toContain('DummyViewport.js?v=1')
			->toContain('dummyViewportSetting')
			->and(str_ends_with(rtrim($out), '</div>'))->toBeTrue();
	});

	it('allows read-only query* UI actions in ProcessWire demo mode ($config->demo) while blocking state-mutating POST actions', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->htmx;
		$htmx->registerComponent('DummyCounter', HtmxV4TestDummyComponent::class);

		$config = wire()->config;
		$previousDemo = (bool) ($config->demo ?? false);
		$previousCsrf = (bool) ($config->protectCSRF ?? true);
		$config->demo = true;
		$config->protectCSRF = false;

		try {
			$cmp = new HtmxV4TestDummyComponent();
			$cmp->fill(['id' => 'demo-test', 'count' => 10, 'label' => 'demo-ready']);
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $cmp->renderStatePayload(), $m);
			$signedState = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

			// 1. Read-only query* action (even over POST) works normally in demo mode
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			$_SERVER['REQUEST_METHOD'] = 'POST';
			wire()->input->post->hx__state = $signedState;
			wire()->input->post->__htmx_action = 'querySummary';
			wire()->input->post->prefix = 'DemoView';

			$cmpQuery = new HtmxV4TestDummyComponent();
			$cmpQuery->setPreDecodedState(Component::decodeSignedPayload($signedState, $cmp->getHmacSalt()));
			$cmpQuery->hydrate();
			$executedQuery = $cmpQuery->executeAction();
			expect($executedQuery)->toBeTrue()
				->and($cmpQuery->renderToString())->toBe("<span class='summary'>DemoView: 10 (demo-ready)</span>");

			// 2. State-mutating POST action (non-query*) is blocked in demo mode and emits warning notice
			wire()->input->post->__htmx_action = 'increment';
			wire()->input->post->step = '5';
			wire()->input->post->label = 'mutated';

			$cmpMutate = new HtmxV4TestDummyComponent();
			$cmpMutate->setPreDecodedState(Component::decodeSignedPayload($signedState, $cmp->getHmacSalt()));
			$cmpMutate->hydrate();
			$executedMutation = $cmpMutate->executeAction();
			expect($executedMutation)->toBeFalse()
				->and($cmpMutate->count)->toBe(10)
				->and($cmpMutate->label)->toBe('demo-ready');
		} finally {
			$config->demo = $previousDemo;
			$config->protectCSRF = $previousCsrf;
		}
	});

	it('registers module component and UI paths via the explicit registration API and resolves through handleEndpoint()', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->htmx;

		$tempDir = rtrim(sys_get_temp_dir(), '/\\') . '/htmx-explicit-mod-' . uniqid('', true);
		$componentsDir = $tempDir . '/components';
		$uiDir = $tempDir . '/ui';
		mkdir($componentsDir, 0777, true);
		mkdir($uiDir, 0777, true);

		file_put_contents(
			$uiDir . '/StandaloneExplicitChip.php',
			<<<'PHP'
<?php
declare(strict_types=1);
namespace Htmx\Ui;
use Totoglu\Htmx\Ui;
class StandaloneExplicitChip extends Ui
{
	public array $defaultParams = ['label' => ''];
	public function render(): string
	{
		return '<span class="explicit-chip">' . $this->esc($this->parameters->getString('label')) . '</span>';
	}
}
PHP
		);

		file_put_contents(
			$componentsDir . '/StandaloneExplicitWidget.php',
			<<<'PHP'
<?php
declare(strict_types=1);
namespace Htmx\Component;
use Htmx\Ui\StandaloneExplicitChip;
use Totoglu\Htmx\Component;
class StandaloneExplicitWidget extends Component
{
	public int $count = 1;
	public function queryStatus(string $tag = 'OK'): string
	{
		return StandaloneExplicitChip::make(['label' => "{$tag}:{$this->count}"])->render();
	}
	public function render(): string
	{
		$chip = StandaloneExplicitChip::make(['label' => "Init:{$this->count}"])->render();
		return "<div id='explicit-widget'>{$chip}{$this->renderStatePayload()}</div>";
	}
}
PHP
		);

		try {
			$htmx->registerModulePaths($tempDir, [
				'StandaloneExplicitWidget' => 'Htmx\\Component\\StandaloneExplicitWidget',
			]);

			$normalizedComponentsDir = rtrim(str_replace('\\', '/', $componentsDir), '/') . '/';
			$normalizedUiDir = rtrim(str_replace('\\', '/', $uiDir), '/') . '/';

			expect($htmx->allowedComponentPaths)->toContain($normalizedComponentsDir)
				->and($htmx->allowedComponentPaths)->toContain($normalizedUiDir);

			$rendered = $htmx->renderComponent('StandaloneExplicitWidget', ['count' => 9]);
			expect($rendered)->toContain('<span class="explicit-chip">Init:9</span>');

			preg_match('/name="hx__state"\s+value="([^"]+)"/', $rendered, $m);
			expect($m)->toHaveKey(1);
			$signedState = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			$_SERVER['REQUEST_METHOD'] = 'GET';
			wire()->input->get->hx__state = $signedState;
			wire()->input->get->__htmx_action = 'queryStatus';
			wire()->input->get->tag = 'Ready';

			$event = new HookEvent();
			$endpointHtml = $htmx->handleEndpoint($event);
			expect($endpointHtml)->toBe('<span class="explicit-chip">Ready:9</span>');
		} finally {
			@unlink($uiDir . '/StandaloneExplicitChip.php');
			@unlink($componentsDir . '/StandaloneExplicitWidget.php');
			@rmdir($uiDir);
			@rmdir($componentsDir);
			@rmdir($tempDir);
		}
	});

	it('resolves module directories whose folder name differs from the module class name', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->htmx;
		$config = wire()->config;

		$parentDir = rtrim(sys_get_temp_dir(), '/\\') . '/htmx-custom-folder-' . uniqid('', true);
		$moduleFolder = $parentDir . '/CustomFolderName';
		$componentsDir = $moduleFolder . '/components';
		mkdir($componentsDir, 0777, true);

		$moduleClass = 'ProcessMismatchedDirModule';
		file_put_contents($moduleFolder . '/' . $moduleClass . '.module.php', "<?php namespace ProcessWire; class {$moduleClass} extends WireData implements Module {}");
		file_put_contents(
			$componentsDir . '/MismatchedFolderComponent.php',
			<<<'PHP'
<?php
declare(strict_types=1);
namespace Htmx\Component;
use Totoglu\Htmx\Component;
class MismatchedFolderComponent extends Component
{
	public string $status = 'resolved';
	public function render(): string
	{
		return "<div data-status='{$this->status}'>{$this->renderStatePayload()}</div>";
	}
}
PHP
		);

		try {
			$expectedDir = rtrim(str_replace('\\', '/', $moduleFolder), '/') . '/';
			expect($htmx->resolveModuleDirectory($moduleClass, null, $parentDir))->toBe($expectedDir);

			$config->setPath($moduleClass, $moduleFolder . '/');

			$htmx->registerModulePaths($moduleClass, [
				'MismatchedFolderComponent' => 'Htmx\\Component\\MismatchedFolderComponent',
			]);

			$rendered = $htmx->renderComponent('MismatchedFolderComponent', ['status' => 'custom-dir-ok']);
			expect($rendered)->toContain("data-status='custom-dir-ok'");
		} finally {
			@unlink($componentsDir . '/MismatchedFolderComponent.php');
			@unlink($moduleFolder . '/' . $moduleClass . '.module.php');
			@rmdir($componentsDir);
			@rmdir($moduleFolder);
			@rmdir($parentDir);
		}
	});
});
