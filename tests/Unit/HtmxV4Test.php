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

if (!enum_exists('HtmxV4TestPriorityEnum')) {
	enum HtmxV4TestPriorityEnum: int
	{
		case Low = 1;
		case High = 2;
	}
}

if (!enum_exists('HtmxV4TestModeEnum')) {
	enum HtmxV4TestModeEnum: string
	{
		case Standard = 'standard';
		case Compact = 'compact';
	}
}

if (!class_exists('HtmxV4TestCacheInjectComponent')) {
	class HtmxV4TestCacheInjectComponent extends Component
	{
		public string $userBio = 'Literal <!--__HTMX_STATE_PAYLOAD__--> marker';

		public function render(): string
		{
			return '<div id="' . htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8') . '">' . $this->renderStatePayload() . '<p>' . $this->userBio . '</p></div>';
		}
	}
}

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

if (!class_exists('HtmxV4TestPageComponent')) {
	class HtmxV4TestPageComponent extends Component
	{
		public ?\ProcessWire\Page $record = null;
		public ?\ProcessWire\PageArray $records = null;

		public function render(): string
		{
			$id = $this->record instanceof \ProcessWire\Page && $this->record->id ? $this->record->id : 0;
			$ids = $this->records instanceof \ProcessWire\PageArray ? implode(',', $this->records->explode('id')) : '';
			return "<section data-record='{$id}' data-records='{$ids}'>{$this->renderStatePayload()}</section>";
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
			->and($req->requestType())->toBe('partial')
			->and($req->isPartial())->toBeTrue()
			->and($req->isFullPage())->toBeFalse()
			->and($req->rawTarget())->toBe('div#main-panel')
			->and($req->targetTag())->toBe('div')
			->and($req->target())->toBe('main-panel')
			->and($req->rawSource())->toBe('button#save-btn')
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
		$frag->addPartial('#counter', '<span id="counter">9</span>', 'innerHTML');
		$frag->addPartial('#sync-box', '<div id="sync-box">Sync</div>', 'outerSync');

		$combined = $frag->renderPartials('<main>Primary</main>');

		expect($combined)->toContain('<main>Primary</main>')
			->and($combined)->toContain('<hx-partial hx-target="#toast-container" hx-swap="outerMorph" hx-select=".toast">' . "\n" . '<div class="toast">Saved!</div>' . "\n" . '</hx-partial>')
			->and($combined)->toContain('<hx-partial hx-target="#counter" hx-swap="innerHTML">' . "\n" . '<span id="counter">9</span>' . "\n" . '</hx-partial>')
			->and($combined)->toContain('<hx-partial hx-target="#sync-box" hx-swap="outerSync">' . "\n" . '<div id="sync-box">Sync</div>' . "\n" . '</hx-partial>');
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

			// 2. State-mutating POST action (non-query*) is blocked in demo mode, purges mutating POST/FILES, and emits warning notice
			wire()->input->post->__htmx_action = 'increment';
			wire()->input->post->step = '5';
			wire()->input->post->label = 'mutated';
			$_FILES['upload'] = ['name' => 'demo.txt', 'tmp_name' => '/tmp/demo', 'size' => 4, 'error' => 0];

			$cmpMutate = new HtmxV4TestDummyComponent();
			$cmpMutate->setPreDecodedState(Component::decodeSignedPayload($signedState, $cmp->getHmacSalt()));
			$cmpMutate->hydrate();
			$executedMutation = $cmpMutate->executeAction();
			expect($executedMutation)->toBeFalse()
				->and($cmpMutate->count)->toBe(10)
				->and($cmpMutate->label)->toBe('demo-ready')
				->and(wire()->input->post('step'))->toBeNull()
				->and(wire()->input->post('label'))->toBeNull()
				->and($_FILES)->toBeEmpty();
		} finally {
			$config->demo = $previousDemo;
			$config->protectCSRF = $previousCsrf;
			$_FILES = [];
		}
	});

	it('manages HTTP method, URI, input bags, sanitizers, demo-mode downgrade, and header-carried CSRF tokens via WireInput on Request', function (): void {
		$req = new Request();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		wire()->input->post->title = '  <b>Native WireInput</b>  ';
		wire()->input->post->qty = '42';
		wire()->input->post->user = ['email' => '  test@example.com  '];
		wire()->input->get->filter = 'active';

		expect($req->wireInput())->not->toBeNull()
			->and($req->rawMethod())->toBe('POST')
			->and($req->method())->toBe('POST')
			->and($req->isPost())->toBeTrue()
			->and($req->isQuery())->toBeFalse()
			->and($req->post('title', 'text'))->toBe('Native WireInput')
			->and($req->post('qty', 'int'))->toBe(42)
			->and($req->get('filter', ['active', 'archived'], 'none'))->toBe('active')
			->and($req->get('filter', ['archived'], 'none'))->toBe('none')
			->and($req->input('user.email', null, 'email'))->toBe('test@example.com')
			->and($req->all())->toHaveKeys(['title', 'qty', 'user', 'filter'])
			->and($req->postArray())->toHaveKey('qty', '42')
			->and($req->getArray())->toHaveKey('filter', 'active')
			->and($req->uri())->toBeString();

		// _method spoofing for PUT / PATCH / DELETE
		wire()->input->post->_method = 'PATCH';
		expect($req->rawMethod())->toBe('PATCH')
			->and($req->method())->toBe('PATCH')
			->and($req->isMutatingMethod())->toBeTrue();
		unset(wire()->input->post->_method);

		// Demo mode downgrades mutating Request::method() to GET and excludes POST from all()
		$config = wire()->config;
		$previousDemo = (bool) ($config->demo ?? false);
		$config->demo = true;
		try {
			expect($req->isDemoMode())->toBeTrue()
				->and($req->isDemoModeBlocked())->toBeTrue()
				->and($req->rawMethod())->toBe('POST')
				->and($req->method())->toBe('GET')
				->and($req->isPost())->toBeFalse()
				->and($req->all())->toBe(['filter' => 'active']);

			// Spoofed _method=QUERY or unsigned __htmx_action=querySummary over POST CANNOT bypass demo mode
			wire()->input->post->_method = 'QUERY';
			expect($req->rawMethod())->toBe('POST')
				->and($req->isDemoModeBlocked())->toBeTrue();
			unset(wire()->input->post->_method);

			wire()->input->post->__htmx_action = 'querySummary';
			expect($req->isQueryActionRequest())->toBeFalse()
				->and($req->isMutatingMethod())->toBeTrue()
				->and($req->isDemoModeBlocked())->toBeTrue();

			// Verified read-only query* action WITH valid HMAC-signed hx__state over POST is allowed,
			// while Htmx::enforceDemoModeGuard() still strips undeclared POST fields and $_FILES
			$queryCmp = new HtmxV4TestDummyComponent();
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $queryCmp->renderStatePayload(), $qm);
			wire()->input->post->hx__state = html_entity_decode($qm[1], ENT_QUOTES, 'UTF-8');
			wire()->input->post->prefix = 'AllowedQueryParam';
			expect($req->isQueryActionRequest())->toBeTrue()
				->and($req->isMutatingMethod())->toBeFalse()
				->and($req->isDemoModeBlocked())->toBeFalse()
				->and($req->method())->toBe('POST');

			/** @var Htmx $htmx */
			$htmx = wire()->htmx;
			$_FILES['avatar'] = ['name' => 'a.png', 'tmp_name' => '/tmp/a', 'size' => 1, 'error' => 0];
			expect($htmx->enforceDemoModeGuard())->toBeFalse()
				->and(wire()->input->post('prefix'))->toBe('AllowedQueryParam')
				->and(wire()->input->post('title'))->toBeNull()
				->and($_FILES)->toBeEmpty();

			unset(wire()->input->post->__htmx_action, wire()->input->post->hx__state, wire()->input->post->prefix);

			// Htmx::enforceDemoModeGuard() purges mutating POST/FILES and downgrades REQUEST_METHOD to GET
			wire()->input->post->title = 'Mutating Title';
			$_FILES['avatar'] = ['name' => 'a.png', 'tmp_name' => '/tmp/a', 'size' => 1, 'error' => 0];
			expect($htmx->enforceDemoModeGuard())->toBeTrue()
				->and(wire()->input->post('title'))->toBeNull()
				->and($_SERVER['REQUEST_METHOD'])->toBe('GET')
				->and(wire()->input->requestMethod('POST'))->toBeFalse()
				->and($_FILES)->toBeEmpty();
		} finally {
			$config->demo = $previousDemo;
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_FILES = [];
		}

		// HTMX v4 QUERY method support
		$_SERVER['REQUEST_METHOD'] = 'QUERY';
		expect($req->method())->toBe('QUERY')
			->and($req->isQuery())->toBeTrue()
			->and($req->isPost())->toBeFalse();

		// Header-carried CSRF token (X-CSRF-TOKEN) bridges into WireInput::$post for native SessionCSRF::validate()
		$_SERVER['REQUEST_METHOD'] = 'POST';
		wire()->input->post->removeAll();
		$csrf = wire()->session->CSRF;
		$tokenName = (string) $csrf->getTokenName();
		$tokenValue = (string) $csrf->getTokenValue();

		$_SERVER['HTTP_X_CSRF_TOKEN'] = $tokenValue;
		try {
			$req->syncCsrfToPost();
			expect(wire()->input->post($tokenName))->toBe($tokenValue)
				->and($req->validateCsrf(true))->toBeTrue();
		} finally {
			unset($_SERVER['HTTP_X_CSRF_TOKEN']);
		}
	});

	it('sanitizes dangerous URI schemes in Response navigation headers and suppresses production Component error leakage', function (): void {
		$res = new Response();
		$res->redirect('javascript:alert(1)');
		$res->pushUrl('data:text/html,<script>alert(1)</script>');
		$res->replaceUrl('vbscript:msgbox(1)');
		$res->location(['path' => 'javascript:evil()', 'target' => '#main']);

		expect($res->getHeader('HX-Redirect'))->toBe('/')
			->and($res->getHeader('HX-Push-Url'))->toBe('/')
			->and($res->getHeader('HX-Replace-Url'))->toBe('/')
			->and($res->getHeader('HX-Location'))->toBe('{"path":"/","target":"#main"}');

		$config = wire()->config;
		$previousDebug = (bool) ($config->debug ?? false);
		$config->debug = false;
		try {
			$failingCmp = new class extends Component {
				public function render(): string
				{
					throw new \RuntimeException('Sensitive database detail');
				}
			};
			expect((string) $failingCmp)->toBe('');
		} finally {
			$config->debug = $previousDebug;
		}
	});

	it('enforces RBAC viewable(), trash checks, and principal (__uid) binding when hydrating state payloads', function (): void {
		$pages = wire()->pages;
		$home = $pages->get(1);
		$adminRoot = $pages->get(2); // Admin page (not viewable by guest)

		// Switch temporarily to guest user so adminRoot (id=2) is not viewable
		$users = wire()->users;
		$previousUser = wire()->user;
		$guestUser = $users->getGuestUser();
		$superuser = $users->get('name=admin|superuser') ?: $users->get(41);

		try {
			// Sign a state payload as superuser first to verify cross-principal replay protection
			$adminSignedState = null;
			if ($superuser && $superuser->id && (int) $superuser->id !== (int) $guestUser->id) {
				$users->setCurrentUser($superuser);
				$superCmp = new HtmxV4TestDummyComponent();
				preg_match('/name="hx__state"\s+value="([^"]+)"/', $superCmp->renderStatePayload(), $sm);
				$adminSignedState = html_entity_decode($sm[1], ENT_QUOTES, 'UTF-8');
			}

			$users->setCurrentUser($guestUser);

			if ($adminSignedState !== null) {
				$replayCmp = new HtmxV4TestDummyComponent();
				$replayCmp->setPreDecodedState(Component::decodeSignedPayload($adminSignedState, $replayCmp->getHmacSalt()));
				expect(fn () => $replayCmp->hydrate())->toThrow(\ProcessWire\WireException::class);

				/** @var Htmx $htmx */
				$htmx = wire()->htmx;
				$htmx->registerComponent('HtmxV4TestDummyComponent', HtmxV4TestDummyComponent::class);
				wire()->input->get->hx__state = $adminSignedState;
				wire()->input->get->__htmx_action = 'querySummary';
				$replayResp = $htmx->handleEndpoint(new HookEvent());
				expect($replayResp)->toContain('Invalid HTMX State Signature');
				wire()->input->get->removeAll();
			}

			$cmp = new HtmxV4TestPageComponent();
			$cmp->record = $home;
			$list = $pages->newPageArray();
			$list->add($home);
			$list->add($adminRoot);
			$cmp->records = $list;

			preg_match('/name="hx__state"\s+value="([^"]+)"/', $cmp->renderStatePayload(), $m);
			$signedState = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

			// 1. PageArray hydration filters out unviewable adminRoot (id=2), keeping viewable home (id=1)
			$hydrated = new HtmxV4TestPageComponent();
			$hydrated->setPreDecodedState(Component::decodeSignedPayload($signedState, $cmp->getHmacSalt()));
			$hydrated->hydrate();

			expect($hydrated->record)->not->toBeNull()
				->and($hydrated->record->id)->toBe(1)
				->and($hydrated->records)->not->toBeNull()
				->and($hydrated->records->explode('id'))->toBe([1]);

			// 2. Single Page hydration rejects unviewable page (id=2) for guest user
			$cmpAdmin = new HtmxV4TestPageComponent();
			$cmpAdmin->record = $adminRoot;
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $cmpAdmin->renderStatePayload(), $mAdmin);
			$signedAdminState = html_entity_decode($mAdmin[1], ENT_QUOTES, 'UTF-8');

			$hydratedAdmin = new HtmxV4TestPageComponent();
			$hydratedAdmin->setPreDecodedState(Component::decodeSignedPayload($signedAdminState, $cmpAdmin->getHmacSalt()));
			$hydratedAdmin->hydrate();

			expect($hydratedAdmin->record)->toBeNull();
		} finally {
			$users->setCurrentUser($previousUser);
		}
	});

	it('rejects directory traversal in Component view paths, Fragment::renderFile, and MakeHtmxComponentCommand (plus demo-mode block)', function (): void {
		$cmp = new class extends Component {
		};
		$cmp->setView('../../wire/config.php');

		// Must not render files outside allowed template/component paths
		expect($cmp->render())->toBe('')
			->and((new Fragment())->renderFile('../../wire/config.php'))->toBe('');

		$cmd = new \Totoglu\Htmx\Commands\MakeHtmxComponentCommand();
		$tester = new \Symfony\Component\Console\Tester\CommandTester($cmd);
		$exitCode = $tester->execute([
			'name' => '../../EvilTraversal',
		]);
		expect($exitCode)->toBe(\Symfony\Component\Console\Command\Command::FAILURE);

		// Demo mode blocks CLI file scaffolding
		$config = wire()->config;
		$previousDemo = (bool) ($config->demo ?? false);
		$config->demo = true;
		try {
			$demoExitCode = $tester->execute([
				'name' => 'DemoBlockedComponent',
			]);
			expect($demoExitCode)->toBe(\Symfony\Component\Console\Command\Command::FAILURE);
		} finally {
			$config->demo = $previousDemo;
		}
	});

	it('auto-discovers module component and UI directories across installed modules including mismatched folder names', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->htmx;
		$config = wire()->config;

		// Ensure at least one installed module exposes a temporary ui/ folder during discovery test
		$viteUiDir = rtrim((string) $config->paths->siteModules, '/') . '/Vite/ui';
		$createdTempDir = false;
		if (!is_dir($viteUiDir) && is_dir(dirname($viteUiDir))) {
			@mkdir($viteUiDir, 0755, true);
			$createdTempDir = true;
		}

		try {
			$ref = new \ReflectionMethod($htmx, 'buildModuleComponentPaths');
			/** @var array<int, array{module: string, components?: string, ui?: string}> $paths */
			$paths = $ref->invoke($htmx);

			expect($paths)->toBeArray()
				->and($paths)->not->toBeEmpty();

			foreach ($paths as $entry) {
				expect($entry)->toHaveKey('module');
				if (isset($entry['components'])) {
					expect(is_dir($entry['components']))->toBeTrue();
				}
				if (isset($entry['ui'])) {
					expect(is_dir($entry['ui']))->toBeTrue();
				}
			}
		} finally {
			if ($createdTempDir && is_dir($viteUiDir)) {
				@rmdir($viteUiDir);
			}
		}
	});

	it('cleans up cache and fuel on ___uninstall() and omits hardcoded version in composer.json', function () {
		$composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
		expect($composer)->not->toHaveKey('version')
			->and($composer['require']['php'] ?? null)->toBe('>=8.4');

		$cache = new class {
			public array $deleted = [];

			public function delete(string $key): void
			{
				$this->deleted[] = $key;
			}
		};
		$fuel = new class {
			public array $removed = [];

			public function remove(string $key): void
			{
				$this->removed[] = $key;
			}
		};

		$wire = new class($cache, $fuel) {
			public function __construct(
				public object $cache,
				public object $fuel,
			) {
			}
		};

		$htmx = new class($wire) extends \ProcessWire\Htmx {
			public function __construct(private readonly object $mockWire)
			{
			}

			public function wire($name = '', $value = null, $lock = false)
			{
				if ($name === '') {
					return $this->mockWire;
				}
				return $this->mockWire->{$name} ?? null;
			}
		};

		$htmx->___install();
		expect($cache->deleted)->toContain('htmx.module-components');

		$cache->deleted = [];
		$htmx->___uninstall();
		expect($cache->deleted)->toContain('htmx.module-components')
			->and($fuel->removed)->toContain('htmx');
	});

	it('excludes overridden Component lifecycle methods (mount, render, renderReady, id, setId) from remote __htmx_action dispatch and guards HtmxCacheClearCommand in demo mode', function () {
		$comp = new class extends Component {
			public int $mountCalls = 0;

			public function mount(): void
			{
				$this->mountCalls++;
			}

			public function renderReady(): bool
			{
				return true;
			}

			public function render(): string
			{
				return '<div id="' . $this->id() . '">ok</div>';
			}

			public function customAction(): void
			{
			}
		};
		wire()->wire($comp);
		$comp->htmx?->cache->setEnabled(false);

		$refMethod = new ReflectionMethod(Component::class, 'buildReflectionSchema');
		/** @var array<string, mixed> $schema */
		$schema = $refMethod->invoke(null, $comp::class);

		expect($schema['actions'])->toHaveKey('customAction')
			->and($schema['actions'])->not->toHaveKey('mount')
			->and($schema['actions'])->not->toHaveKey('render')
			->and($schema['actions'])->not->toHaveKey('renderReady')
			->and($schema['actions'])->not->toHaveKey('id')
			->and($schema['actions'])->not->toHaveKey('setId');

		$config = wire()->config;
		$prevProtectCsrf = $config->protectCSRF;
		$prevDemo = $config->demo ?? false;
		$prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;

		try {
			$config->protectCSRF = false;
			$config->demo = false;
			$_SERVER['REQUEST_METHOD'] = 'POST';

			expect($comp->executeAction('mount'))->toBeFalse()
				->and($comp->mountCalls)->toBe(0)
				->and($comp->executeAction('render'))->toBeFalse();

			// Verify HtmxCacheClearCommand allows --stats in demo mode, while blocking clear / --prune
			$config->demo = true;
			$cmd = new \Totoglu\Htmx\Commands\HtmxCacheClearCommand();
			$tester = new \Symfony\Component\Console\Tester\CommandTester($cmd);

			$statsExit = $tester->execute(['--stats' => true, '--json' => true]);
			$statsJson = json_decode($tester->getDisplay(), true);
			expect($statsExit)->toBe(0)
				->and($statsJson['ok'] ?? null)->toBeTrue();

			$clearExit = $tester->execute(['--json' => true]);
			$clearJson = json_decode($tester->getDisplay(), true);
			expect($clearExit)->toBe(1)
				->and($clearJson['ok'] ?? null)->toBeFalse()
				->and($clearJson['error']['code'] ?? null)->toBe('DEMO_MODE');

			$pruneExit = $tester->execute(['--prune' => true, '--json' => true]);
			$pruneJson = json_decode($tester->getDisplay(), true);
			expect($pruneExit)->toBe(1)
				->and($pruneJson['ok'] ?? null)->toBeFalse()
				->and($pruneJson['error']['code'] ?? null)->toBe('DEMO_MODE');
		} finally {
			$config->protectCSRF = $prevProtectCsrf;
			$config->demo = $prevDemo;
			if ($prevMethod === null) {
				unset($_SERVER['REQUEST_METHOD']);
			} else {
				$_SERVER['REQUEST_METHOD'] = $prevMethod;
			}
		}
	});

	it('prevents attribute/tag/hyperscript injection, isolates component render cache by user, guards demo mode post() + inline CSRF, and hardens JS helpers', function (): void {
		// 1. AttributeBag blocks attribute-name injection while keeping HTMX v4 colon/modifier attributes
		$bag = new \Totoglu\Htmx\Bag\AttributeBag([
			'hx-status:422' => 'swap:outerMorph',
			'hx-confirm:inherited' => 'Are you sure?',
			'data-bad onload=alert(1)' => '1',
			'bad"onclick="alert(1)' => '2',
			"bad\nkey" => '3',
		]);
		$renderedAttrs = $bag->render();
		expect($renderedAttrs)->toContain('hx-status:422="swap:outerMorph"')
			->and($renderedAttrs)->toContain('hx-confirm:inherited="Are you sure?"')
			->and($renderedAttrs)->not->toContain('onload')
			->and($renderedAttrs)->not->toContain('onclick');

		// 2. Ui::compileOpenTag sanitizes invalid/script tag names and Fragment::addPartial validates swap styles unconditionally
		$tagSanitizer = new class extends \Totoglu\Htmx\Ui {
			public static function checkOpen(string $tag): string
			{
				return self::compileOpenTag($tag, '');
			}
		};
		expect($tagSanitizer::checkOpen('section'))->toContain("'<section'")
			->and($tagSanitizer::checkOpen('div onload=alert(1)'))->toContain("'<div'")
			->and($tagSanitizer::checkOpen('script'))->toContain("'<div'");

		$frag = new Fragment();
		wire()->wire($frag);
		$frag->addPartial('#safe-box', '<div>Content</div>', 'invalid" onload="alert(1)');
		$partialHtml = $frag->renderPartials();
		expect($partialHtml)->toContain('hx-swap="outerMorph"')
			->and($partialHtml)->not->toContain('onload');

		// 3. Component render cache key includes user authentication scope (guest vs logged-in user)
		$authComp = new class extends Component {
		};
		wire()->wire($authComp);
		$keyMethod = new ReflectionMethod(Component::class, 'buildRenderCacheKeyData');

		$users = wire()->users;
		$originalUser = wire()->user;
		$guestUser = $users->getGuestUser();
		$superUser = $users->get('roles=superuser');
		try {
			$users->setCurrentUser($guestUser);
			/** @var array<string, mixed> $guestKey */
			$guestKey = $keyMethod->invoke($authComp);
			expect($guestKey['auth'] ?? null)->toBe('guest');

			if ($superUser && $superUser->id) {
				$users->setCurrentUser($superUser);
				/** @var array<string, mixed> $adminKey */
				$adminKey = $keyMethod->invoke($authComp);
				expect($adminKey['auth'] ?? null)->toBe('u:' . (int) $superUser->id)
					->and($adminKey)->not->toBe($guestKey);
			}
		} finally {
			if ($originalUser) {
				$users->setCurrentUser($originalUser);
			}
		}

		// 4. Request::post() / postArray() block user inputs in demo mode BEFORE enforceDemoModeGuard() runs,
		//    and enforceDemoModeGuard() preserves CSRF token so inline Component::executeAction() hits onDemoModeBlocked() instead of WireCSRFException
		/** @var \ProcessWire\Htmx $htmx */
		$htmx = wire()->modules->get('Htmx');
		$config = wire()->config;
		$input = wire()->input;
		$csrf = wire()->session->CSRF;

		$prevDemo = $config->demo ?? false;
		$prevProtectCsrf = $config->protectCSRF;
		$prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;

		try {
			$config->demo = true;
			$config->protectCSRF = true;
			$_SERVER['REQUEST_METHOD'] = 'POST';

			$tokenName = (string) $csrf->getTokenName();
			$tokenValue = (string) $csrf->getTokenValue();

			$input->post->set('title', 'Untrusted Demo Mutation');
			$input->post->set('__htmx_action', 'saveDemoItem');
			$input->post->set($tokenName, $tokenValue);

			// Even before enforceDemoModeGuard() runs, user POST fields are hidden from Request::post() / postArray()
			expect($htmx->request->post('title'))->toBeNull()
				->and($htmx->request->post('title', null, 'safe_default'))->toBe('safe_default')
				->and($htmx->request->postArray())->toBe([]);

			// Simulate Page::render before-hook followed by inline Component::executeAction()
			expect($htmx->enforceDemoModeGuard())->toBeTrue();

			$inlineComp = new class extends Component {
				public bool $saved = false;
				public string $noticeMessage = '';

				public function saveDemoItem(): void
				{
					$this->saved = true;
				}
			};
			wire()->wire($inlineComp);

			expect($inlineComp->executeAction())->toBeFalse()
				->and($inlineComp->saved)->toBeFalse()
				->and($inlineComp->noticeMessage)->toContain('demo mode')
				->and($input->post($tokenName))->toBeNull();
		} finally {
			$config->demo = $prevDemo;
			$config->protectCSRF = $prevProtectCsrf;
			$input->post->removeAll();
			if ($prevMethod === null) {
				unset($_SERVER['REQUEST_METHOD']);
			} else {
				$_SERVER['REQUEST_METHOD'] = $prevMethod;
			}
		}

		// 5. Client-side JS helpers enforce same-origin CSRF attachment and safe runtime asset/config loading
		$csrfJs = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/htmx/pw-csrf.js');
		$runtimeJs = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/htmx/pw-htmx-runtime.js');
		expect($csrfJs)->toContain('pwHtmxIsSameOrigin(request.action)')
			->and($csrfJs)->toContain('window.location.origin')
			->and($runtimeJs)->toContain('BLOCKED_CONFIG_KEYS')
			->and($runtimeJs)->toContain('isSafeAssetUrl');

		// 6. Sanitizer parity (HPP array flattening + int toggle), Vary header, safe asset URLs, and state size bounds
		$req = new Request();
		wire()->wire($req);
		wire()->input->get->arrText = ['  <b>Safe</b>  ', 'ignored'];
		wire()->input->get->flag = '1';
		wire()->input->get->badFlag = '1abc';
		expect($req->input('arrText', '', 'text'))->toBe('Safe')
			->and($req->input('flag', 0, 1))->toBe(1)
			->and($req->input('badFlag', 0, 1))->toBe(0)
			->and($req->input('arrText', 'none', ['  <b>Safe</b>  ']))->toBe('  <b>Safe</b>  ');
		wire()->input->get->removeAll();

		$res = new Response();
		$res->vary()->vary('Accept-Encoding');
		expect($res->getHeader('Vary'))->toBe('HX-Request, HX-Boosted, Accept-Encoding')
			->and(Htmx::isSafeAssetUrl('/site/templates/app.js'))->toBeTrue()
			->and(Htmx::isSafeAssetUrl('javascript:alert(1)'))->toBeFalse()
			->and(Htmx::isSafeAssetUrl('data:text/javascript,alert(1)'))->toBeFalse();

		$oversized = str_repeat('A', Component::MAX_STATE_PAYLOAD_BYTES + 10) . '|deadbeef';
		expect(fn () => Component::decodeSignedPayload($oversized, 'test-salt'))
			->toThrow(\ProcessWire\WireException::class);
	});

	it('prevents JSON body smuggling in demo mode on verified POST query* actions, blocks GET method spoofing, confines Fragment::renderFile, and hardens extensions/symlinks', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->modules->get('Htmx');
		$config = wire()->config;
		$input = wire()->input;

		$prevDemo = (bool) ($config->demo ?? false);
		$prevDebug = (bool) ($config->debug ?? false);
		$prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;
		$prevContentType = $_SERVER['CONTENT_TYPE'] ?? null;
		$prevOverride = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;

		try {
			// 1. GET requests cannot spoof DELETE via X-HTTP-Method-Override and do not sync JSON into $input->post
			$input->post->removeAll();
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_SERVER['CONTENT_TYPE'] = 'application/json';
			$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'DELETE';

			$getReq = new Request();
			wire()->wire($getReq);
			$getReq->setRawJsonBody((string) json_encode(['injectedOnGet' => 'nope']));
			$getReq->syncJsonToPost();

			expect($getReq->rawMethod())->toBe('GET')
				->and($getReq->method())->toBe('GET')
				->and($getReq->post('injectedOnGet'))->toBeNull()
				->and($input->post('injectedOnGet'))->toBeNull();

			unset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);

			// 2. POST with JSON _method=PATCH and native SAPI PATCH (where WireInput::requestMethod() is '') resolve accurately
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$postJsonReq = new Request();
			wire()->wire($postJsonReq);
			$postJsonReq->setRawJsonBody((string) json_encode(['_method' => 'PATCH', 'field' => 'ok']));
			expect($postJsonReq->rawMethod())->toBe('PATCH');

			$_SERVER['REQUEST_METHOD'] = 'PATCH';
			$nativePatchReq = new Request();
			wire()->wire($nativePatchReq);
			expect($nativePatchReq->rawMethod())->toBe('PATCH')
				->and($nativePatchReq->isMutatingMethod())->toBeTrue();

			// 3. In $config->demo = true, verified POST query* with application/json allows ONLY declared params and blocks JSON re-sync smuggling
			$config->demo = true;
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['CONTENT_TYPE'] = 'application/json';
			$input->post->removeAll();

			$queryCmp = new HtmxV4TestDummyComponent();
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $queryCmp->renderStatePayload(), $qm);
			$signedState = html_entity_decode($qm[1], ENT_QUOTES, 'UTF-8');

			$htmx->request->setRawJsonBody((string) json_encode([
				'__htmx_action' => 'querySummary',
				'hx__state' => $signedState,
				'prefix' => 'SafeJsonQuery',
				'smuggledJson' => 'must-be-blocked',
			]));
			$input->post->set('undeclaredFormField', 'must-be-stripped');

			expect($htmx->request->isQueryActionRequest())->toBeTrue()
				->and($htmx->enforceDemoModeGuard())->toBeFalse()
				->and($htmx->request->post('prefix'))->toBe('SafeJsonQuery')
				->and($htmx->request->post('smuggledJson'))->toBeNull()
				->and($htmx->request->post('undeclaredFormField'))->toBeNull()
				->and($htmx->request->postArray())->not->toHaveKey('smuggledJson')
				->and($htmx->request->json())->not->toHaveKey('smuggledJson')
				->and($htmx->request->all())->not->toHaveKey('smuggledJson')
				->and($htmx->request->input('smuggledJson'))->toBeNull()
				->and($input->post('smuggledJson'))->toBeNull();

			$config->demo = false;
			$htmx->request->setRawJsonBody(null);

			// 4. Fragment::renderFile confines paths to templates / allowedComponentPaths and passes $vars properly
			$frag = new Fragment();
			wire()->wire($frag);
			$moduleFile = dirname(__DIR__, 2) . '/Htmx.module.php';
			expect($frag->renderFile($moduleFile))->toBe('')
				->and($frag->renderFile('phar://archive.phar/view.php'))->toBe('');

			$safePartial = rtrim((string) $config->paths->templates, '/') . '/_htmx_pass6_partial_' . uniqid('', true) . '.php';
			file_put_contents($safePartial, '<?php echo "SAFE:" . ($greeting ?? "");');
			try {
				expect($frag->renderFile(basename($safePartial), ['greeting' => 'Hello']))->toBe('SAFE:Hello');
			} finally {
				@unlink($safePartial);
			}

			// 5. Extension identifier whitelist & debug HTML comment escaping
			$htmx->loadExtension('../../htmx');
			foreach ($htmx->getAssetUrls() as $assetUrl) {
				expect($assetUrl)->not->toContain('..');
			}

			$config->debug = true;
			$debugOut = $htmx->renderComponent('Bad--> <script>alert(1)</script>');
			expect($debugOut)->toContain('Bad--&gt; &lt;script&gt;alert(1)&lt;/script&gt;')
				->and($debugOut)->not->toContain('--> <script>');

			// 6. Non-builtin typed property hydration guard prevents TypeError on invalid scalar/null values
			$typedCmp = new class extends Component {
				public \DateTimeImmutable $createdAt;
				public ?\DateTimeImmutable $updatedAt = null;

				public function __construct()
				{
					parent::__construct();
					$this->createdAt = new \DateTimeImmutable('2026-01-01T00:00:00Z');
				}
			};
			wire()->wire($typedCmp);
			$refMap = new \ReflectionMethod(Component::class, 'mapStateToProperties');
			$refMap->invoke($typedCmp, [
				'createdAt' => 'not-an-object',
				'updatedAt' => ['invalid-array'],
			]);
			expect($typedCmp->createdAt)->toBeInstanceOf(\DateTimeImmutable::class)
				->and($typedCmp->updatedAt)->toBeNull();

			// 7. HtmxCache directory deletion unlinks symlinks without traversing into external target directories
			$extTargetDir = sys_get_temp_dir() . '/htmx_symlink_target_' . uniqid('', true);
			$cacheTestDir = sys_get_temp_dir() . '/htmx_cache_dir_' . uniqid('', true);
			mkdir($extTargetDir, 0755, true);
			mkdir($cacheTestDir, 0755, true);
			$protectedExternalFile = $extTargetDir . '/keep.txt';
			file_put_contents($protectedExternalFile, 'must-survive');
			$symlinkPath = $cacheTestDir . '/linked_dir';
			@symlink($extTargetDir, $symlinkPath);

			try {
				$cacheInstance = new \Totoglu\Htmx\Cache\HtmxCache($cacheTestDir);
				wire()->wire($cacheInstance);
				$delMethod = new \ReflectionMethod(\Totoglu\Htmx\Cache\HtmxCache::class, 'deleteDirectoryFiles');
				$delMethod->invoke($cacheInstance, $cacheTestDir, false);

				expect(file_exists($protectedExternalFile))->toBeTrue()
					->and(is_link($symlinkPath))->toBeFalse();
			} finally {
				if (is_link($symlinkPath) || is_file($symlinkPath)) {
					unlink($symlinkPath);
				}
				if (is_file($protectedExternalFile)) {
					unlink($protectedExternalFile);
				}
				if (is_dir($extTargetDir)) {
					rmdir($extTargetDir);
				}
				if (is_dir($cacheTestDir)) {
					rmdir($cacheTestDir);
				}
			}
		} finally {
			$config->demo = $prevDemo;
			$config->debug = $prevDebug;
			$htmx->request->setRawJsonBody(null);
			$input->post->removeAll();
			if ($prevMethod === null) {
				unset($_SERVER['REQUEST_METHOD']);
			} else {
				$_SERVER['REQUEST_METHOD'] = $prevMethod;
			}
			if ($prevContentType === null) {
				unset($_SERVER['CONTENT_TYPE']);
			} else {
				$_SERVER['CONTENT_TYPE'] = $prevContentType;
			}
			if ($prevOverride === null) {
				unset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
			} else {
				$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = $prevOverride;
			}
		}
	});

	it('enforces CSRF and demo guards across PUT/PATCH/DELETE, syncs non-POST URL-encoded bodies, and safely handles BackedEnums and missing required action params', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->modules->get('Htmx');
		$config = wire()->config;
		$input = wire()->input;
		$session = wire()->session;
		$csrf = $session->CSRF;

		$prevDemo = (bool) $config->demo;
		$prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;
		$prevContentType = $_SERVER['CONTENT_TYPE'] ?? null;
		$prevCsrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

		$tokenServerHeader = null;

		try {
			$verbCmp = new class extends Component {
				public int $deletedId = 0;
				public string $patchedNote = 'none';
				public HtmxV4TestPriorityEnum $priority = HtmxV4TestPriorityEnum::Low;
				public HtmxV4TestModeEnum $mode = HtmxV4TestModeEnum::Standard;

				public function deleteRow(int $rowId): void
				{
					$this->deletedId = $rowId;
				}

				public function updateNote(string $note, HtmxV4TestPriorityEnum $priority = HtmxV4TestPriorityEnum::High): void
				{
					$this->patchedNote = $note;
					$this->priority = $priority;
				}

				public function queryFilter(string $term = 'all'): string
				{
					return "filtered:{$term}";
				}

				public function render(): string
				{
					return "<div>{$this->deletedId}|{$this->patchedNote}|{$this->priority->value}|{$this->mode->value}</div>";
				}
			};
			wire()->wire($verbCmp);

			// 1. PUT request with ?__htmx_action=queryFilter in GET query string must NOT execute from GET without body action or bypass CSRF
			$config->demo = false;
			$input->get->removeAll();
			$input->post->removeAll();
			$htmx->request->setRawJsonBody(null);
			$_SERVER['REQUEST_METHOD'] = 'PUT';
			unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER[$tokenServerHeader]);
			$input->get->set('__htmx_action', 'queryFilter');
			expect($verbCmp->executeAction())->toBeFalse();

			// 2. PUT / PATCH with application/x-www-form-urlencoded raw body syncs to $input->post and requires CSRF
			$input->get->removeAll();
			$input->post->removeAll();
			$_SERVER['REQUEST_METHOD'] = 'PATCH';
			$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded; charset=UTF-8';
			$htmx->request->setRawJsonBody('__htmx_action=updateNote&note=from-patch&priority=2');

			expect(fn () => $verbCmp->executeAction())->toThrow(\ProcessWire\WireCSRFException::class);

			// Provide valid X-CSRF-TOKEN header -> PATCH executes cleanly and coerces int-backed enum '2'
			$expectedToken = (string) $csrf->getTokenValue();
			$_SERVER['HTTP_X_CSRF_TOKEN'] = $expectedToken;
			$input->post->removeAll();
			$htmx->request->setRawJsonBody('__htmx_action=updateNote&note=from-patch&priority=2');

			expect($verbCmp->executeAction())->toBeTrue()
				->and($verbCmp->patchedNote)->toBe('from-patch')
				->and($verbCmp->priority)->toBe(HtmxV4TestPriorityEnum::High)
				->and($input->post('note'))->toBe('from-patch');

			// 3. DELETE (HTMX v4 sends params in GET query string + X-{tokenName} header) enforces CSRF and blocks in demo mode
			unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['CONTENT_TYPE']);
			$input->get->removeAll();
			$input->post->removeAll();
			$htmx->request->setRawJsonBody(null);
			$_SERVER['REQUEST_METHOD'] = 'DELETE';
			$input->get->set('__htmx_action', 'deleteRow');
			$input->get->set('rowId', '42');

			expect(fn () => $verbCmp->executeAction())->toThrow(\ProcessWire\WireCSRFException::class);

			// Missing required non-nullable int parameter returns false cleanly without throwing TypeError
			$tokenName = (string) $csrf->getTokenName();
			$tokenServerHeader = 'HTTP_X_' . strtoupper(str_replace('-', '_', $tokenName));
			$expectedToken = (string) $csrf->getTokenValue();
			$_SERVER[$tokenServerHeader] = $expectedToken;
			$input->get->removeAll();
			$input->post->removeAll();
			$input->get->set('__htmx_action', 'deleteRow');
			expect($verbCmp->executeAction())->toBeFalse()
				->and($verbCmp->deletedId)->toBe(0);

			// Valid required rowId + valid X-{tokenName} header executes DELETE cleanly
			$input->get->set('rowId', '42');
			expect($verbCmp->executeAction())->toBeTrue()
				->and($verbCmp->deletedId)->toBe(42);

			// Demo mode ($config->demo = true) blocks DELETE mutation and non-POST URL-encoded PATCH body
			$config->demo = true;
			$verbCmp->deletedId = 7;
			expect($verbCmp->executeAction())->toBeFalse()
				->and($verbCmp->deletedId)->toBe(7);

			$_SERVER['REQUEST_METHOD'] = 'PATCH';
			$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
			$input->post->removeAll();
			$htmx->request->setRawJsonBody('__htmx_action=updateNote&note=blocked-in-demo');
			expect($htmx->request->isDemoModeBlocked())->toBeTrue()
				->and($htmx->request->post('note'))->toBeNull()
				->and($verbCmp->executeAction())->toBeFalse()
				->and($verbCmp->patchedNote)->toBe('from-patch');

			// 4. HTMX v4 QUERY method with application/x-www-form-urlencoded body works even in $config->demo = true
			$_SERVER['REQUEST_METHOD'] = 'QUERY';
			$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
			$input->get->removeAll();
			$input->post->removeAll();
			$htmx->request->setRawJsonBody('__htmx_action=queryFilter&term=invoices');
			expect($htmx->request->isDemoModeBlocked())->toBeFalse()
				->and($verbCmp->executeAction())->toBeTrue()
				->and((string) $verbCmp)->toBe('filtered:invoices');

			$config->demo = false;

			// 5. BackedEnum strict_types safety in fill(), dehydration, and hydration (string '2' -> int enum, invalid -> ignored)
			$verbCmp->fill([
				'priority' => '2',
				'mode' => 'compact',
			]);
			expect($verbCmp->priority)->toBe(HtmxV4TestPriorityEnum::High)
				->and($verbCmp->mode)->toBe(HtmxV4TestModeEnum::Compact);

			$buildStateRef = new \ReflectionMethod(Component::class, 'buildStateFromProperties');
			$dehydrated = $buildStateRef->invoke($verbCmp);
			expect($dehydrated['priority'] ?? null)->toBe(2)
				->and($dehydrated['mode'] ?? null)->toBe('compact');

			$verbCmp->priority = HtmxV4TestPriorityEnum::Low;
			$verbCmp->mode = HtmxV4TestModeEnum::Standard;
			$mapStateRef = new \ReflectionMethod(Component::class, 'mapStateToProperties');
			$mapStateRef->invoke($verbCmp, [
				'priority' => '2',
				'mode' => 999,
			]);
			expect($verbCmp->priority)->toBe(HtmxV4TestPriorityEnum::High)
				->and($verbCmp->mode)->toBe(HtmxV4TestModeEnum::Standard);

			// 6. Request::validateCsrf() syncs JSON-borne _token before validating
			if ($tokenServerHeader !== null) {
				unset($_SERVER[$tokenServerHeader]);
			}
			unset($_SERVER['HTTP_X_CSRF_TOKEN']);
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['CONTENT_TYPE'] = 'application/json';
			$input->post->removeAll();
			$expectedToken = (string) $csrf->getTokenValue();
			$htmx->request->setRawJsonBody((string) json_encode(['_token' => $expectedToken, 'field' => 'ok']));
			expect($htmx->request->validateCsrf(true))->toBeTrue()
				->and($input->post('field'))->toBe('ok');
		} finally {
			$config->demo = $prevDemo;
			$htmx->request->setRawJsonBody(null);
			$input->get->removeAll();
			$input->post->removeAll();
			if ($prevMethod === null) {
				unset($_SERVER['REQUEST_METHOD']);
			} else {
				$_SERVER['REQUEST_METHOD'] = $prevMethod;
			}
			if ($prevContentType === null) {
				unset($_SERVER['CONTENT_TYPE']);
			} else {
				$_SERVER['CONTENT_TYPE'] = $prevContentType;
			}
			if ($prevCsrfHeader === null) {
				unset($_SERVER['HTTP_X_CSRF_TOKEN']);
			} else {
				$_SERVER['HTTP_X_CSRF_TOKEN'] = $prevCsrfHeader;
			}
			if ($tokenServerHeader !== null) {
				unset($_SERVER[$tokenServerHeader]);
			}
		}
	});

	it('hardens autoExtractTargets entity preservation, js:/HCON headers, JSON AttributeBag, QUERY isolation, fuel/env leaks, and __wire_model schema checks', function (): void {
		/** @var Htmx $htmx */
		$htmx = wire()->htmx;
		$config = wire()->config;
		$input = wire()->input;
		$session = wire()->session;
		$csrf = $session->CSRF;

		$prevDemo = (bool) ($config->demo ?? false);
		$prevHttpHost = $config->httpHost;
		$prevHttpHosts = $config->httpHosts;
		$prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;
		$prevHxRequest = $_SERVER['HTTP_HX_REQUEST'] ?? null;
		$prevHxTarget = $_SERVER['HTTP_HX_TARGET'] ?? null;
		$prevContentType = $_SERVER['CONTENT_TYPE'] ?? null;
		$prevCsrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
		$prevAutoExtract = $htmx->autoExtractTargets;

		try {
			// 1. autoExtractTargets must preserve escaped HTML entities (&lt;script&gt;, &quot;) without decoding into XSS
			$htmx->autoExtractTargets = true;
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			$_SERVER['HTTP_HX_TARGET'] = 'comment-box';
			$_SERVER['REQUEST_METHOD'] = 'GET';

			$homePage = wire()->pages->get('/');
			$escapedUserPayload = '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;quoted&quot;';
			$renderedFragment = '';
			$testHookId = wire()->addHookBefore('Page::render', static function (\ProcessWire\HookEvent $ev) use (&$renderedFragment, $escapedUserPayload): void {
				$ev->replace = true;
				$ev->return = '<!DOCTYPE html><html><body><main><div id="comment-box"><p>' . $escapedUserPayload . '</p></div></main></body></html>';
			}, ['priority' => 50]);
			try {
				$renderedFragment = (string) $homePage->render();
			} finally {
				wire()->removeHook($testHookId);
			}
			expect($renderedFragment)->toContain('id="comment-box"')
				->and($renderedFragment)->toContain('&lt;img src=x onerror=alert(1)&gt;')
				->and($renderedFragment)->not->toContain('<img src=x onerror=alert(1)>');

			// 2. Response blocks HTMX v4 js: scheme, HCON injection, option-key path overrides, and cross-origin pushUrl/replaceUrl
			$config->httpHost = 'example.com';
			$config->httpHosts = ['example.com'];
			$resp = new Response();
			wire()->wire($resp);

			$resp->redirect('js:alert(1)');
			expect($resp->getHeader('HX-Redirect'))->toBe('/');

			$resp->location('{path:js:alert(1)}');
			expect($resp->getHeader('HX-Location'))->toBe('/');

			$resp->location('path:js:alert(1)');
			expect($resp->getHeader('HX-Location'))->toBe('/');

			$resp->location('/safe-target', [
				'path' => 'js:alert(1)',
				'confirm' => '  js:javascript:Are you sure?',
				'target' => '#panel',
				'evilKey' => 'dropped',
			]);
			$locDecoded = json_decode((string) $resp->getHeader('HX-Location'), true);
			expect($locDecoded)->toBe([
				'path' => '/safe-target',
				'target' => '#panel',
				'confirm' => 'Are you sure?',
			]);

			$resp->pushUrl('//evil.example/phish');
			expect($resp->getHeader('HX-Push-Url'))->toBe('/');
			$resp->replaceUrl('https://evil.example/phish');
			expect($resp->getHeader('HX-Replace-Url'))->toBe('/');
			$resp->pushUrl('https://example.com/account');
			expect($resp->getHeader('HX-Push-Url'))->toBe('https://example.com/account');

			// 3. AttributeBag JSON encoding prevents &quot; breakout, and Ui neutralizes js:/javascript: in confirm & URLs
			$ui = (new class extends \Totoglu\Htmx\Ui {
				public function render(): string
				{
					return '<button' . $this->attributes->renderRuntimeAttributes() . '>Go</button>';
				}
			})
				->vals(['note' => 'a&quot;,"__htmx_action":"destroy","x":"b'])
				->confirm('js:Delete this record?')
				->action('js:evil()')
				->hx('post', 'javascript:evil()');

			$uiHtml = (string) $ui;
			expect($uiHtml)->toContain('hx-confirm="Delete this record?"')
				->and($uiHtml)->toContain('hx-action="/"')
				->and($uiHtml)->toContain('hx-post="/"')
				->and($uiHtml)->toContain('\u0026quot;');

			$attrMatch = [];
			preg_match('/hx-vals="([^"]+)"/', $uiHtml, $attrMatch);
			$browserDecodedVals = html_entity_decode($attrMatch[1] ?? '', ENT_QUOTES, 'UTF-8');
			$parsedVals = json_decode($browserDecodedVals, true);
			expect($parsedVals)->toBe(['note' => 'a&quot;,"__htmx_action":"destroy","x":"b'])
				->and($parsedVals)->not->toHaveKey('__htmx_action');

			// 4. Request::get() blocks Wire::__get() fuel object leaks and header() blocks non-HTTP $_SERVER leaks
			$input->get->removeAll();
			$_SERVER['DOCUMENT_ROOT'] = '/var/www/secret-root';
			$_SERVER['DB_SECRET_ENV'] = 'top-secret';
			expect($htmx->request->get('page'))->toBeNull()
				->and($htmx->request->get('user'))->toBeNull()
				->and($htmx->request->get('config'))->toBeNull()
				->and($htmx->request->get('session'))->toBeNull()
				->and($htmx->request->header('DOCUMENT_ROOT'))->toBeNull()
				->and($htmx->request->header('DB_SECRET_ENV', 'fallback-hdr'))->toBe('fallback-hdr');
			unset($_SERVER['DB_SECRET_ENV']);

			// 5. QUERY body does NOT pollute global $input->post; GET/QUERY reject validateCsrf() & syncCsrfToPost()
			$config->demo = false;
			$input->post->removeAll();
			$_SERVER['REQUEST_METHOD'] = 'QUERY';
			$_SERVER['CONTENT_TYPE'] = 'application/json';
			$_SERVER['HTTP_X_CSRF_TOKEN'] = (string) $csrf->getTokenValue();
			$htmx->request->setRawJsonBody((string) json_encode(['submit' => '1', 'q' => 'ledger']));
			$htmx->request->syncJsonToPost();
			$htmx->request->syncCsrfToPost();

			expect($input->post('submit'))->toBeNull()
				->and($input->post((string) $csrf->getTokenName()))->toBeNull()
				->and($htmx->request->input('q'))->toBe('ledger')
				->and($htmx->request->validateCsrf(false))->toBeFalse();

			// 6. Demo mode blocks piggybacked ?__htmx_action=querySummary on POST and protects ready() before URL hooks
			$signedCmp = new HtmxV4TestDummyComponent();
			$signedCmp->fill(['id' => 'demo-piggy', 'count' => 3, 'label' => 'init']);
			$stateMarkup = $signedCmp->renderStatePayload();
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $stateMarkup, $sm);
			$validSignedState = html_entity_decode($sm[1] ?? '', ENT_QUOTES, 'UTF-8');

			$config->demo = true;
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
			$htmx->request->setRawJsonBody(null);
			$input->get->removeAll();
			$input->post->removeAll();
			$input->get->set('__htmx_action', 'querySummary');
			$input->get->set('hx__state', $validSignedState);
			$input->post->set('legacy_delete', '1');

			expect($htmx->request->resolveVerifiedQueryAction())->toBeNull()
				->and($htmx->request->isDemoModeBlocked())->toBeTrue()
				->and($htmx->request->method())->toBe('GET');

			$htmx->ready();
			expect($input->post('legacy_delete'))->toBeNull();

			// 7. __wire_model schema-type enforcement, plain-array marker stripping, sibling action isolation, and Fragment OOB > / $1 safety
			$config->demo = false;
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$input->get->removeAll();
			$input->post->removeAll();

			$modelGuardCmp = new class extends Component {
				public array $items = ['initial'];
				public mixed $flexible = 'safe';
				public bool $ranMutate = false;

				public function mutate(): void
				{
					$this->ranMutate = true;
				}

				public function render(): string
				{
					return 'ok';
				}
			};
			$modelGuardCmp->items = ['__wire_model' => 'Page', 'id' => 1, 'nested' => ['__wire_model' => 'PageArray', 'ids' => [1]]];
			$dehydrateRef = new \ReflectionMethod(Component::class, 'buildStateFromProperties');
			$dehydratedArr = $dehydrateRef->invoke($modelGuardCmp);
			expect($dehydratedArr['items'])->not->toHaveKey('__wire_model')
				->and($dehydratedArr['items']['nested'])->not->toHaveKey('__wire_model');

			$hydrateMapRef = new \ReflectionMethod(Component::class, 'mapStateToProperties');
			$modelGuardCmp->items = ['clean'];
			$hydrateMapRef->invoke($modelGuardCmp, [
				'items' => ['__wire_model' => 'Page', 'id' => 1],
				'flexible' => ['__wire_model' => 'Page', 'id' => 1],
			]);
			expect($modelGuardCmp->items)->toBe(['clean'])
				->and($modelGuardCmp->flexible)->toBe('safe');

			// Stripped hx__state with __htmx_action=increment must NOT match or execute on renderComponent()
			$input->post->removeAll();
			$input->post->set('__htmx_action', 'increment');
			$input->post->set((string) $csrf->getTokenName(), (string) $csrf->getTokenValue());
			$strippedOut = $htmx->renderComponent(HtmxV4TestDummyComponent::class, ['id' => 'iso', 'count' => 10, 'label' => 'unchanged']);
			expect($strippedOut)->toContain("data-count='10'");

			// Fragment::isValidSwapStyle rejects quote breakout payloads
			$frag = new Fragment();
			wire()->wire($frag);
			$frag->addPartial('#oob-box', '<div id="oob-box">Price $100</div>', 'outerMorph');
			$oobOut = $frag->renderPartials();
			expect($oobOut)->toContain('hx-swap="outerMorph">' . "\n" . '<div id="oob-box">Price $100</div>')
				->and($frag->isValidSwapStyle('outerMorph" onload="alert(1)'))->toBeFalse();

			$csrfJs = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/htmx/pw-csrf.js');
			expect($csrfJs)->toContain("window.location.origin === 'null'")
				->and($csrfJs)->toContain("method === 'head' || method === 'options'");

			$input->post->set('cmd', 'hello');
			expect($htmx->request->input('cmd', 'fallback', 'strtoupper'))->toBe('fallback')
				->and($htmx->request->input('cmd', 'fallback', 'text'))->toBe('hello');
		} finally {
			$config->demo = $prevDemo;
			$config->httpHost = $prevHttpHost;
			$config->httpHosts = $prevHttpHosts;
			$htmx->autoExtractTargets = $prevAutoExtract;
			$htmx->request->setRawJsonBody(null);
			$input->get->removeAll();
			$input->post->removeAll();
			if ($prevMethod === null) {
				unset($_SERVER['REQUEST_METHOD']);
			} else {
				$_SERVER['REQUEST_METHOD'] = $prevMethod;
			}
			if ($prevHxRequest === null) {
				unset($_SERVER['HTTP_HX_REQUEST']);
			} else {
				$_SERVER['HTTP_HX_REQUEST'] = $prevHxRequest;
			}
			if ($prevHxTarget === null) {
				unset($_SERVER['HTTP_HX_TARGET']);
			} else {
				$_SERVER['HTTP_HX_TARGET'] = $prevHxTarget;
			}
			if ($prevContentType === null) {
				unset($_SERVER['CONTENT_TYPE']);
			} else {
				$_SERVER['CONTENT_TYPE'] = $prevContentType;
			}
			if ($prevCsrfHeader === null) {
				unset($_SERVER['HTTP_X_CSRF_TOKEN']);
			} else {
				$_SERVER['HTTP_X_CSRF_TOKEN'] = $prevCsrfHeader;
			}
		}
	});

	it('enforces Pass-9 HCON location escaping, URL-encoded POST CSRF sync, demo $_REQUEST/activeDemoAllowedKeys guards, DI RBAC, and path confinement', function (): void {
		/** @var \ProcessWire\Htmx $htmx */
		$htmx = wire()->modules->get('Htmx');
		$config = wire()->config;
		$input = wire()->input;
		$csrf = wire()->session->CSRF;

		$prevDemo = (bool) $config->demo;
		$prevMaxPageNum = $config->maxPageNum;
		$prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;
		$prevContentType = $_SERVER['CONTENT_TYPE'] ?? null;
		$prevCsrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
		$prevPromptHeader = $_SERVER['HTTP_HX_PROMPT'] ?? null;
		$prevHxRequest = $_SERVER['HTTP_HX_REQUEST'] ?? null;
		$prevRequestSuperglobal = $_REQUEST;

		try {
			// 1. Response::location() JSON-wraps URLs with HCON delimiters/quotes/braces and rejects protocol-relative // URLs
			$res = new Response();
			wire()->wire($res);
			$res->location('/search?tags=news,path:js:alert(1)');
			expect($res->getHeader('HX-Location'))->toBe('{"path":"/search?tags=news,path:js:alert(1)"}');

			$resPlain = new Response();
			wire()->wire($resPlain);
			$resPlain->location('/dashboard/items?page=2');
			expect($resPlain->getHeader('HX-Location'))->toBe('/dashboard/items?page=2');

			$resBlocked = new Response();
			wire()->wire($resBlocked);
			$resBlocked->location('//evil.example/hijack')
				->redirect('//evil.example/hijack')
				->pushUrl('//evil.example/hijack')
				->replaceUrl('//evil.example/hijack')
				->setHeader("X-Bad\r\nName:Injected", "clean\r\nvalue");
			expect($resBlocked->getHeader('HX-Location'))->toBe('/')
				->and($resBlocked->getHeader('HX-Redirect'))->toBe('/')
				->and($resBlocked->getHeader('HX-Push-Url'))->toBe('/')
				->and($resBlocked->getHeader('HX-Replace-Url'))->toBe('/')
				->and($resBlocked->getHeader('X-BadNameInjected'))->toBe('cleanvalue');

			// 2. URL-encoded POST syncJsonToPost() bridges X-CSRF-TOKEN and verified POST query* validateCsrf() succeeds
			$config->demo = false;
			$input->get->removeAll();
			$input->post->removeAll();
			$htmx->request->setRawJsonBody(null);
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			$tokenName = (string) $csrf->getTokenName();
			$tokenValue = (string) $csrf->getTokenValue();
			$_SERVER['HTTP_X_CSRF_TOKEN'] = $tokenValue;

			$htmx->request->syncJsonToPost();
			expect($input->post->get($tokenName))->toBe($tokenValue)
				->and($htmx->request->validateCsrf())->toBeTrue();

			$signedQueryCmp = new HtmxV4TestDummyComponent();
			$signedQueryCmp->fill(['id' => 'csrf-q', 'count' => 2, 'label' => 'ok']);
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $signedQueryCmp->renderStatePayload(), $qm);
			$validQueryState = html_entity_decode($qm[1] ?? '', ENT_QUOTES, 'UTF-8');

			$input->post->removeAll();
			$input->post->set('__htmx_action', 'querySummary');
			$input->post->set('hx__state', $validQueryState);
			expect($htmx->request->validateCsrf())->toBeTrue();

			// 3. Self-enforcing activeDemoAllowedKeys() before enforceDemoModeGuard() + $_REQUEST purging in demo mode
			$freshReq = new Request();
			wire()->wire($freshReq);
			$config->demo = true;
			$input->post->removeAll();
			$input->post->set('__htmx_action', 'querySummary');
			$input->post->set('hx__state', $validQueryState);
			$input->post->set('smuggled_write', 'evil');
			$_POST['smuggled_write'] = 'evil';
			$_REQUEST['smuggled_write'] = 'evil';

			expect($freshReq->post('smuggled_write'))->toBeNull()
				->and($freshReq->input('smuggled_write'))->toBeNull()
				->and($freshReq->post('__htmx_action'))->toBe('querySummary');

			$freshReq->restrictDemoQueryInputs(['__htmx_action', 'hx__state']);
			expect($_REQUEST)->not->toHaveKey('smuggled_write');

			$freshReq->purgeMutatingGlobals();
			expect($_REQUEST)->toBe($_GET);

			// 4. postArray()/getArray() key sanitization, pageNumber() clamping, prompt() rawurldecode(), and hasHeader()
			$config->demo = false;
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$input->get->removeAll();
			$input->post->removeAll();
			$input->get->set('__proto__', 'polluted-get');
			$input->get->set('q', 'clean');
			$input->post->set('constructor', 'polluted-post');
			$input->post->set('title', 'safe');
			expect($htmx->request->getArray())->toHaveKey('q', 'clean')
				->and($htmx->request->getArray())->not->toHaveKey('__proto__')
				->and($htmx->request->postArray())->toHaveKey('title', 'safe')
				->and($htmx->request->postArray())->not->toHaveKey('constructor');

			$config->maxPageNum = 50;
			$input->get->set('page', '1e30');
			expect($htmx->request->pageNumber())->toBe(1);
			$input->get->set('page', '999999');
			expect($htmx->request->pageNumber())->toBe(50);

			$_SERVER['HTTP_HX_PROMPT'] = rawurlencode('Merhaba Dünya 100%');
			expect($htmx->request->prompt())->toBe('Merhaba Dünya 100%')
				->and($htmx->request->hasHeader('HX-Prompt'))->toBeTrue()
				->and($htmx->request->hasHeader('X-Non-Existent-Header'))->toBeFalse();

			// 5. resolveConfinedSiteSubdir blocks NUL-byte coalescing and site/assets escapes; buildAssetConfigScript emits delta only
			$confineRef = new \ReflectionMethod($htmx, 'resolveConfinedSiteSubdir');
			$siteDir = (string) $config->paths->get('site');
			expect($confineRef->invoke($htmx, $siteDir, "components/.\0./secret"))->toBeNull()
				->and($confineRef->invoke($htmx, $siteDir, 'assets/logs'))->toBeNull()
				->and($confineRef->invoke($htmx, $siteDir, 'templates'))->toEndWith('/templates/');

			$deltaRef = new \ReflectionMethod($htmx, 'buildAssetConfigScript');
			$deltaScript = (string) $deltaRef->invoke(
				$htmx,
				['componentWidgetOpts' => ['theme' => 'dark']],
				[],
				[]
			);
			expect($deltaScript)->toContain('componentWidgetOpts')
				->and($deltaScript)->not->toContain('"newJsConfig"');

			// 6. Component DI resolves WireInput / Request + non-$page Page RBAC, and hydrate() enforces explicitId match
			$diCmp = new class extends Component {
				public string $injectedUrl = '';
				public ?int $targetId = null;

				public function inspect(\ProcessWire\WireInput $input, Request $req, ?\ProcessWire\Page $target = null): void
				{
					$this->injectedUrl = $req->method() . ':' . ($input === wire()->input ? 'same' : 'diff');
					$this->targetId = $target?->id;
				}

				public function render(): string
				{
					return '<div>' . $this->injectedUrl . ':' . ($this->targetId ?? 'null') . '</div>';
				}
			};
			$input->post->removeAll();
			$input->post->set('__htmx_action', 'inspect');
			$input->post->set('target', '1');
			expect($diCmp->executeAction())->toBeTrue()
				->and($diCmp->injectedUrl)->toBe('POST:same')
				->and($diCmp->targetId)->toBe(1);

			// Unviewable / trashed page IDs must resolve to null instead of leaking restricted Page instances
			$input->post->set('target', '7'); // Trash page ID in ProcessWire is 7
			expect($diCmp->executeAction())->toBeTrue()
				->and($diCmp->targetId)->toBeNull();

			// Explicit ID mismatch must reject hydration
			$explicitCmp = new HtmxV4TestDummyComponent();
			$explicitCmp->fill(['id' => 'explicit-one', 'count' => 5]);
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $explicitCmp->renderStatePayload(), $em);
			$explicitSigned = html_entity_decode($em[1] ?? '', ENT_QUOTES, 'UTF-8');

			$autoCmp = new HtmxV4TestDummyComponent();
			$autoCmp->hydrate($explicitSigned);
			expect($autoCmp->count)->toBe(0);
		} finally {
			$config->demo = $prevDemo;
			$config->maxPageNum = $prevMaxPageNum;
			$_REQUEST = $prevRequestSuperglobal;
			$htmx->request->setRawJsonBody(null);
			$input->get->removeAll();
			$input->post->removeAll();
			if ($prevMethod === null) {
				unset($_SERVER['REQUEST_METHOD']);
			} else {
				$_SERVER['REQUEST_METHOD'] = $prevMethod;
			}
			if ($prevContentType === null) {
				unset($_SERVER['CONTENT_TYPE']);
			} else {
				$_SERVER['CONTENT_TYPE'] = $prevContentType;
			}
			if ($prevCsrfHeader === null) {
				unset($_SERVER['HTTP_X_CSRF_TOKEN']);
			} else {
				$_SERVER['HTTP_X_CSRF_TOKEN'] = $prevCsrfHeader;
			}
			if ($prevPromptHeader === null) {
				unset($_SERVER['HTTP_HX_PROMPT']);
			} else {
				$_SERVER['HTTP_HX_PROMPT'] = $prevPromptHeader;
			}
			if ($prevHxRequest === null) {
				unset($_SERVER['HTTP_HX_REQUEST']);
			} else {
				$_SERVER['HTTP_HX_REQUEST'] = $prevHxRequest;
			}
		}
	});

	it('enforces Pass-10 admin demo login exemption, endpoint-bound query* replay protection, random render-cache tokens, User hydration block, and asset/swap hardening', function (): void {
		/** @var \ProcessWire\Htmx $htmx */
		$htmx = wire()->modules->get('Htmx');
		$config = wire()->config;
		$input = wire()->input;
		$csrf = wire()->session->CSRF;

		$prevDemo = (bool) $config->demo;
		$prevHttps = (bool) $config->https;
		$prevHttpHost = (string) $config->httpHost;
		$prevHttpHosts = $config->httpHosts;
		$prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;
		$prevUri = $_SERVER['REQUEST_URI'] ?? null;
		$prevHxRequest = $_SERVER['HTTP_HX_REQUEST'] ?? null;
		$prevPromptHeader = $_SERVER['HTTP_HX_PROMPT'] ?? null;
		$prevContentType = $_SERVER['CONTENT_TYPE'] ?? null;
		$prevGet = $_GET;

		try {
			// 1. Request extends Wire (not WireData): magic property lookup must NOT fall back to $_GET
			$_GET['magic_leak_probe'] = 'leaked-from-get';
			expect($htmx->request)->toBeInstanceOf(\ProcessWire\Wire::class)
				->and($htmx->request)->not->toBeInstanceOf(\ProcessWire\WireData::class)
				->and($htmx->request->magic_leak_probe)->toBeNull();
			unset($_GET['magic_leak_probe']);

			// 2. Demo mode does NOT wipe POST credentials inside admin ($this->inAdmin()), while blocking Modules::saveConfig('Htmx', ...)
			$adminPage = wire()->pages->get(2);
			$prevWirePage = wire()->page;
			try {
				$config->demo = true;
				$_SERVER['REQUEST_METHOD'] = 'POST';
				unset($_SERVER['HTTP_HX_REQUEST']);
				$input->post->removeAll();
				$input->post->set('login_name', 'admin');
				$input->post->set('login_pass', 'demo-pass');
				if ($adminPage instanceof \ProcessWire\Page && $adminPage->id > 0) {
					wire()->fuel->set('page', $adminPage);
					$inAdminRef = new \ReflectionMethod($htmx, 'inAdmin');
					expect($inAdminRef->invoke($htmx))->toBeTrue();
				}

				expect(wire()->modules->saveConfig('Htmx', ['endpointUrl' => '/demo-mutated-endpoint']))->toBeFalse();
			} finally {
				if ($prevWirePage !== null) {
					wire()->fuel->set('page', $prevWirePage);
				}
			}

			// 3. Signed query* state cannot be replayed without HX-Request or against a non-endpoint HTTP URI
			$config->demo = true;
			$signedCmp = new HtmxV4TestDummyComponent();
			$signedCmp->fill(['id' => 'ep-bound', 'count' => 7, 'label' => 'bound']);
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $signedCmp->renderStatePayload(), $m);
			$signedPayload = html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8');

			$_SERVER['REQUEST_METHOD'] = 'POST';
			$input->post->removeAll();
			$input->post->set('__htmx_action', 'querySummary');
			$input->post->set('hx__state', $signedPayload);

			// Missing HX-Request header -> not treated as verified query* action
			unset($_SERVER['HTTP_HX_REQUEST']);
			$_SERVER['REQUEST_URI'] = $htmx->endpointUrl;
			expect($htmx->request->isDemoModeBlocked())->toBeTrue();

			// Present HX-Request header, but mismatched non-endpoint URI -> rejected
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			$_SERVER['REQUEST_URI'] = '/unrelated-contact-form/';
			expect($htmx->request->isDemoModeBlocked())->toBeTrue();

			// Matching endpoint URI + HX-Request -> allowed for read-only query* action
			$_SERVER['REQUEST_URI'] = $htmx->endpointUrl;
			expect($htmx->request->isDemoModeBlocked())->toBeFalse();

			// 4. Request::pageNumber() clamps '0' & '-5' to 1, empty HX-Prompt preserves '' vs null, and non-POST URL-encoded _token syncs CSRF
			$config->demo = false;
			$input->get->set('page', '0');
			expect($htmx->request->pageNumber())->toBe(1);
			$input->get->set('page', '-5');
			expect($htmx->request->pageNumber())->toBe(1);
			$input->get->remove('page');

			$_SERVER['HTTP_HX_PROMPT'] = '';
			expect($htmx->request->hasHeader('HX-Prompt'))->toBeTrue()
				->and($htmx->request->prompt())->toBe('');
			unset($_SERVER['HTTP_HX_PROMPT']);
			expect($htmx->request->prompt())->toBeNull();

			$_SERVER['REQUEST_METHOD'] = 'PUT';
			$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
			$tokenName = (string) $csrf->getTokenName();
			$tokenValue = (string) $csrf->getTokenValue();
			$input->post->removeAll();
			$htmx->request->setRawJsonBody('_token=' . rawurlencode($tokenValue) . '&title=put-item');
			expect($htmx->request->validateCsrf())->toBeTrue()
				->and($input->post->get($tokenName))->toBe($tokenValue);
			$htmx->request->setRawJsonBody(null);

			// 5. AttributeBag::set() neutralizes js:/javascript: on hx-* attributes; Fragment guards ':swap:1s' and </hx-partial> breakout
			$bag = new AttributeBag(['hx-get' => 'js:alert(1)']);
			$bag->set('hx-post', 'javascript:evil()');
			$bag->set('data-hx-vals', 'js:stealCookies()');
			expect((string) $bag->get('hx-get'))->toBe('/')
				->and((string) $bag->get('hx-post'))->toBe('/')
				->and((string) $bag->get('data-hx-vals'))->toBe('{}');

			$frag = new Fragment();
			wire()->wire($frag);
			expect($frag->isValidSwapStyle(':swap:1s'))->toBeFalse()
				->and($frag->isValidSwapStyle('innerHTML:swap:1s'))->toBeTrue();
			$frag->addPartial('#box', '<div>safe</hx-partial><script>bad()</script></div>', 'innerHTML');
			expect($frag->renderPartials())->toContain('&lt;/hx-partial>')
				->and(substr_count($frag->renderPartials(), '</hx-partial>'))->toBe(1);

			// 6. Response::location() / redirect() enforce same-origin & HTTPS; Htmx::isSafeAssetUrl() + data-pw-htmx-token + site/modules confinement
			$config->https = true;
			$config->httpHost = 'secure.example.com';
			$config->httpHosts = ['secure.example.com'];

			$res = new Response();
			wire()->wire($res);
			$res->location('https://evil.example.com/phish')
				->redirect('http://secure.example.com/downgrade');
			expect($res->getHeader('HX-Location'))->toBe('/')
				->and($res->getHeader('HX-Redirect'))->toBe('/');

			$res->redirect('https://external-oauth.example.com/authorize', true);
			expect($res->getHeader('HX-Redirect'))->toBe('https://external-oauth.example.com/authorize');

			expect(Htmx::isSafeAssetUrl('/site/templates/scripts/app.js', $config))->toBeTrue()
				->and(Htmx::isSafeAssetUrl('https://secure.example.com/site/templates/app.css', $config))->toBeTrue()
				->and(Htmx::isSafeAssetUrl('https://cdn.evil.example/payload.js', $config))->toBeFalse()
				->and(Htmx::isSafeAssetUrl('http://secure.example.com/downgrade.js', $config))->toBeFalse()
				->and(Htmx::isSafeAssetUrl('//evil.example/payload.js', $config))->toBeFalse();

			$confineRef = new \ReflectionMethod($htmx, 'resolveConfinedSiteSubdir');
			$siteDir = (string) $config->paths->get('site');
			expect($confineRef->invoke($htmx, $siteDir, 'modules/Htmx'))->toBeNull();

			$deltaRef = new \ReflectionMethod($htmx, 'buildAssetConfigScript');
			$assetScript = (string) $deltaRef->invoke(
				$htmx,
				['csrf' => ['leaked' => true], 'urls' => ['admin' => '/secret/'], 'safeWidget' => ['ok' => 1]],
				['https://cdn.evil.example/bad.js', '/site/templates/good.js'],
				[]
			);
			expect($assetScript)->toContain('data-pw-htmx-token=')
				->and($assetScript)->toContain('safeWidget')
				->and($assetScript)->toContain('/site/templates/good.js')
				->and($assetScript)->not->toContain('cdn.evil.example')
				->and($assetScript)->not->toContain('"leaked"')
				->and($assetScript)->not->toContain('"newJsConfig"');

			// 7. Random render-cache placeholder resists literal <!--__HTMX_STATE_PAYLOAD__--> injection, and Component blocks User subtype on generic Page properties + throws on unviewable non-nullable Page
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$firstRender = (string) (new HtmxV4TestCacheInjectComponent())->enableRenderCache(true, 60);
			$secondRender = (string) (new HtmxV4TestCacheInjectComponent())->enableRenderCache(true, 60);
			expect(substr_count($firstRender, 'name="hx__state"'))->toBe(1)
				->and(substr_count($secondRender, 'name="hx__state"'))->toBe(1)
				->and($secondRender)->toContain('Literal <!--__HTMX_STATE_PAYLOAD__--> marker');

			$rbacCmp = new class extends Component {
				public ?\ProcessWire\Page $optionalRecord = null;
				public \ProcessWire\Page $requiredRecord;

				public function mount(): void
				{
					$this->requiredRecord = wire()->pages->get(1);
				}

				public function render(): string
				{
					return '<div>ok</div>';
				}
			};
			$rbacCmp->mount();
			$hydrateMapRef = new \ReflectionMethod(Component::class, 'mapStateToProperties');

			// User ID (e.g. guest user 40) must NOT hydrate onto a generic Page-typed property
			$guestUserId = (int) (wire()->config->guestUserPageID ?: 40);
			$hydrateMapRef->invoke($rbacCmp, [
				'optionalRecord' => ['__wire_model' => 'Page', 'id' => $guestUserId],
			]);
			expect($rbacCmp->optionalRecord)->toBeNull();

			// Non-nullable Page property failing RBAC hydration (trashed/non-existent ID) must throw WirePermissionException
			expect(static fn() => $hydrateMapRef->invoke($rbacCmp, [
				'requiredRecord' => ['__wire_model' => 'Page', 'id' => 99999999],
			]))->toThrow(\ProcessWire\WirePermissionException::class);
		} finally {
			$config->demo = $prevDemo;
			$config->https = $prevHttps;
			$config->httpHost = $prevHttpHost;
			$config->httpHosts = $prevHttpHosts;
			$_GET = $prevGet;
			$htmx->request->setRawJsonBody(null);
			$input->get->removeAll();
			$input->post->removeAll();
			if ($prevMethod === null) {
				unset($_SERVER['REQUEST_METHOD']);
			} else {
				$_SERVER['REQUEST_METHOD'] = $prevMethod;
			}
			if ($prevUri === null) {
				unset($_SERVER['REQUEST_URI']);
			} else {
				$_SERVER['REQUEST_URI'] = $prevUri;
			}
			if ($prevHxRequest === null) {
				unset($_SERVER['HTTP_HX_REQUEST']);
			} else {
				$_SERVER['HTTP_HX_REQUEST'] = $prevHxRequest;
			}
			if ($prevPromptHeader === null) {
				unset($_SERVER['HTTP_HX_PROMPT']);
			} else {
				$_SERVER['HTTP_HX_PROMPT'] = $prevPromptHeader;
			}
			if ($prevContentType === null) {
				unset($_SERVER['CONTENT_TYPE']);
			} else {
				$_SERVER['CONTENT_TYPE'] = $prevContentType;
			}
		}
	});

	it('enforces Pass-11 deterministic session configToken, hx-config sanitization, POST __ep binding, GET body blocking, and cross-module demo/WireInput parity', function (): void {
		$wire = wire();
		/** @var \ProcessWire\Htmx $htmx */
		$htmx = $wire->modules->get('Htmx');
		$config = $wire->config;
		$input = $wire->input;

		$prevDemo = (bool) $config->demo;
		$prevHttpHost = $config->httpHost;
		$prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;

		try {
			$config->demo = false;
			$htmx->request->resetFake();

			// 1. Session-bound deterministic getAssetConfigToken() matches across calls in the same session & buildCsrfConfigScript() emits configToken
			$tokenA = $htmx->getAssetConfigToken();
			$tokenB = $htmx->getAssetConfigToken();
			expect($tokenA)->not->toBe('')
				->and($tokenB)->toBe($tokenA);

			$csrfScriptRef = new \ReflectionMethod($htmx, 'buildCsrfConfigScript');
			$csrfScriptHtml = (string) $csrfScriptRef->invoke($htmx);
			$assetScriptRef = new \ReflectionMethod($htmx, 'buildAssetConfigScript');
			$assetScriptHtml = (string) $assetScriptRef->invoke($htmx, [], ['demoFlag' => 1], [], []);
			expect($csrfScriptHtml)->toContain('"configToken":')
				->and($csrfScriptHtml)->toContain($tokenA)
				->and($assetScriptHtml)->toContain('data-pw-htmx-config')
				->and($assetScriptHtml)->toContain($tokenA);

			// 2. AttributeBag sanitizes hx-config / data-hx-config (array and JSON string): blocks dangerous schemes & credentials:include
			$bag = new AttributeBag();
			$bag->set('hx-config', [
				'action' => 'js:alert(1)',
				'credentials' => 'include',
				'timeout' => 3000,
			]);
			$decodedCfg = json_decode((string) $bag->get('hx-config'), true);
			expect($decodedCfg)->toBeArray()
				->and($decodedCfg['action'] ?? null)->toBe('/')
				->and($decodedCfg['credentials'] ?? null)->toBe('same-origin')
				->and($decodedCfg['timeout'] ?? null)->toBe(3000);

			$bag->set('data-hx-config', '{"action":"/api/safe","credentials":"omit"}');
			$decodedSafeCfg = json_decode((string) $bag->get('data-hx-config'), true);
			expect($decodedSafeCfg['action'] ?? null)->toBe('/api/safe')
				->and($decodedSafeCfg['credentials'] ?? null)->toBe('omit');

			$bag->set('hx-config', '{action:javascript:alert(1)}');
			expect($bag->get('hx-config'))->toBe('{}');

			// 3. Response::sanitizeNavigationUrl blocks blob: and file:
			$resp = new Response();
			expect($resp->sanitizeNavigationUrl('blob:https://example.com/uuid'))->toBe('/')
				->and($resp->sanitizeNavigationUrl('file:///etc/passwd'))->toBe('/');

			// 4. Component signed __ep endpoint binding blocks cross-endpoint hx__state replay on hydrate() & handleEndpoint()
			$epCmp = new class extends Component {
				public int $step = 1;

				public function increment(): void
				{
					$this->step++;
				}

				public function render(): string
				{
					return '<div>' . $this->step . '</div>';
				}
			};
			$epCmp->setId('ep-bound-cmp');
			$epCmp->setEndpointUrl('/htmx/endpoint-alpha');
			$epCmp->step = 9;
			$stateHtml = $epCmp->renderStatePayload();
			preg_match('/name="hx__state"\s+value="([^"]+)"/', $stateHtml, $stateMatch);
			$signedPayload = html_entity_decode((string) ($stateMatch[1] ?? ''), ENT_QUOTES, 'UTF-8');
			expect($signedPayload)->not->toBe('');

			$replayTarget = clone $epCmp;
			$replayTarget->step = 1;
			$replayTarget->setId('ep-bound-cmp');
			$replayTarget->setEndpointUrl('/htmx/endpoint-beta');
			$input->post->set('hx__state', $signedPayload);
			$htmx->request->fake(['HX-Request' => 'true'], 'POST');
			expect(static fn() => $replayTarget->hydrate())->toThrow(\ProcessWire\WireException::class);
			expect($replayTarget->step)->toBe(1);

			// Render cache key includes scheme://httpHost
			$cacheKeyRef = new \ReflectionMethod($epCmp, 'buildRenderCacheKeyData');
			$config->httpHost = 'alpha.example.com';
			$keyAlpha = $cacheKeyRef->invoke($epCmp);
			$config->httpHost = 'beta.example.com';
			$keyBeta = $cacheKeyRef->invoke($epCmp);
			expect($keyAlpha['host'])->not->toBe($keyBeta['host']);

			// 5. Request::fake(), GET JSON body blocking, blocked key filtering, numeric key preservation, and cross-module PW_DEMO_ORIGINAL_METHOD
			$input->post->removeAll();
			$input->get->removeAll();
			$htmx->request->fake(['Content-Type' => 'application/json', 'HX-Request' => 'true'], 'GET', '{"injected":"via-get"}');
			expect($htmx->request->json())->toBe([])
				->and($htmx->request->input('injected'))->toBeNull()
				->and($htmx->request->isMethod(''))->toBeFalse();

			$htmx->request->fake(['Content-Type' => 'application/json', 'HX-Request' => 'true'], 'POST', '{"0":"override-zero","__proto__":"polluted"}');
			$input->get->set('0', 'initial-zero');
			$mergedAll = $htmx->request->all();
			expect($mergedAll[0] ?? $mergedAll['0'] ?? null)->toBe('override-zero')
				->and($htmx->request->post('__proto__', null, 'blocked'))->toBe('blocked')
				->and($htmx->request->get('__proto__', null, 'blocked'))->toBe('blocked');

			// Simulate Inertia purging $_SERVER['REQUEST_METHOD'] to GET first while preserving PW_DEMO_ORIGINAL_METHOD
			$htmx->request->resetFake();
			$config->demo = true;
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_SERVER['PW_DEMO_ORIGINAL_METHOD'] = 'DELETE';
			expect($htmx->request->rawMethod())->toBe('DELETE')
				->and($htmx->request->method())->toBe('GET')
				->and($htmx->request->isDemoModeBlocked())->toBeTrue();

			// 6. Client JS fail-closed token verification, DELETE CSRF query-leak prevention, and debug header redaction
			$moduleRoot = dirname(__DIR__, 2);
			$runtimeJs = (string) file_get_contents($moduleRoot . '/resources/htmx/pw-htmx-runtime.js');
			$csrfJs = (string) file_get_contents($moduleRoot . '/resources/htmx/pw-csrf.js');
			$debugJs = (string) file_get_contents($moduleRoot . '/resources/htmx/pw-htmx-debug.js');
			expect($runtimeJs)->toContain('if (!expected) {')
				->and($csrfJs)->toContain('window.location.href')
				->and($csrfJs)->toContain("method !== 'delete'")
				->and($debugJs)->toContain('[REDACTED]');
		} finally {
			unset($_SERVER['PW_DEMO_ORIGINAL_METHOD']);
			$config->demo = $prevDemo;
			$config->httpHost = $prevHttpHost;
			$htmx->request->resetFake();
			$input->get->removeAll();
			$input->post->removeAll();
			if ($prevMethod === null) {
				unset($_SERVER['REQUEST_METHOD']);
			} else {
				$_SERVER['REQUEST_METHOD'] = $prevMethod;
			}
		}
	});
});
