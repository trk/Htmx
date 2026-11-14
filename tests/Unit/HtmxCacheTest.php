<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Tests\Unit;

use InvalidArgumentException;
use ProcessWire\WireData;
use ProcessWire\WireInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Totoglu\Htmx\Cache\HtmxCache;
use Totoglu\Htmx\Commands\HtmxCacheClearCommand;
use Totoglu\Htmx\Component;
use Totoglu\Htmx\Fragment;
use Totoglu\Htmx\Response;
use Totoglu\Htmx\Ui;

use function ProcessWire\wire;

/* -------------------------------------------------------------------------
 * Atomic UI Fixtures (Title, Badge, Button, Image)
 * ------------------------------------------------------------------------- */

class TestTitleUi extends Ui
{
	public string $name = 'ui-title';

	public array $shapeParams = [
		'tag' => 'h3',
		'size' => 'md',
	];

	public array $slotParams = [
		'text' => '',
	];

	protected array $valueGateKeys = ['text'];

	public static int $resolveShapeCalls = 0;

	protected function resolveShape(): array
	{
		self::$resolveShapeCalls++;
		$tag = (string) $this->parameters->get('tag', 'h3');
		if (!in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div'], true)) {
			$tag = 'h3';
		}
		$size = (string) $this->parameters->get('size', 'md');
		$this->prependClass(['ui-title', "ui-title-{$size}"]);

		$shape = parent::resolveShape();
		$shape['tag'] = $tag;
		return $shape;
	}

	protected function compileTemplate(array $shape): string
	{
		$tag = (string) $shape['tag'];
		$open = self::compileOpenTag($tag, (string) $shape['classAttr']);
		$close = var_export("</{$tag}>", true);

		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\t\$content = (\$s['text'] ?? '') . \$children;\n"
			. "\treturn {$open} . \$content . {$close};\n"
			. "}";
	}
}

class TestBadgeUi extends Ui
{
	public string $name = 'ui-badge';

	public array $shapeParams = [
		'variant' => 'default',
	];

	public array $slotParams = [
		'label' => '',
		'icon' => '',
	];

	protected array $valueGateKeys = ['label', 'icon'];

	public static int $resolveShapeCalls = 0;

	protected function resolveShape(): array
	{
		self::$resolveShapeCalls++;
		$variant = (string) $this->parameters->get('variant', 'default');
		$this->addClass(['ui-badge', "ui-badge-{$variant}"]);
		return parent::resolveShape();
	}

	protected function compileTemplate(array $shape): string
	{
		$open = self::compileOpenTag('span', (string) $shape['classAttr']);

		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\t\$icon = (\$s['icon'] ?? '') !== '' ? '<i class=\"ui-badge-icon\">' . \$s['icon'] . '</i>' : '';\n"
			. "\t\$label = \$s['label'] ?? '';\n"
			. "\treturn {$open} . \$icon . \$label . \$children . '</span>';\n"
			. "}";
	}
}

class TestImageUi extends Ui
{
	public string $name = 'ui-image';

	public array $shapeParams = [
		'ratio' => 'auto',
	];

	public array $slotParams = [
		'src' => '',
		'alt' => '',
	];

	protected array $valueGateKeys = ['src'];

	public static int $resolveShapeCalls = 0;

	protected function resolveShape(): array
	{
		self::$resolveShapeCalls++;
		$ratio = (string) $this->parameters->get('ratio', 'auto');
		$this->addClass(['ui-image', "ui-image-{$ratio}"]);
		return parent::resolveShape();
	}

	protected function compileTemplate(array $shape): string
	{
		$classAttr = var_export((string) $shape['classAttr'], true);

		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\t\$src = \$s['src'] ?? '';\n"
			. "\t\$alt = \$s['alt'] ?? '';\n"
			. "\treturn '<img' . {$classAttr} . ' src=\"' . \$src . '\" alt=\"' . \$alt . '\"' . (\$attrs['root'] ?? '') . '>';\n"
			. "}";
	}
}

class TestButtonUi extends Ui
{
	public string $name = 'ui-button';

	public array $shapeParams = [
		'tag' => 'button',
		'variant' => 'primary',
	];

	public array $slotParams = [
		'icon' => '',
		'label' => '',
		'trailing' => '',
	];

	protected array $zones = ['left', 'center', 'right'];

	protected array $defaultZones = [
		'icon' => 'left',
		'label' => 'center',
		'trailing' => 'right',
	];

	protected array $valueGateKeys = ['icon', 'label', 'trailing'];

	public static int $resolveShapeCalls = 0;

	protected function resolveShape(): array
	{
		self::$resolveShapeCalls++;
		$variant = (string) $this->parameters->get('variant', 'primary');
		$this->prependClass(['ui-btn', "ui-btn-{$variant}"]);
		$shape = parent::resolveShape();
		$shape['tag'] = (string) $this->parameters->get('tag', 'button');
		return $shape;
	}

	protected function compileTemplate(array $shape): string
	{
		$tag = (string) $shape['tag'];
		$open = self::compileOpenTag($tag, (string) $shape['classAttr']);
		$close = var_export("</{$tag}>", true);
		$zoneMap = $shape['zoneMap'];

		$leftExpr = self::compileZoneItemsExpr($zoneMap['left'] ?? []);
		$centerExpr = self::compileZoneItemsExpr($zoneMap['center'] ?? []);
		$rightExpr = self::compileZoneItemsExpr($zoneMap['right'] ?? []);

		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\t\$left = {$leftExpr};\n"
			. "\t\$center = {$centerExpr} . \$children;\n"
			. "\t\$right = {$rightExpr};\n"
			. "\t\$inner = '';\n"
			. "\tif (\$left !== '') { \$inner .= '<span class=\"ui-btn-left\">' . \$left . '</span>'; }\n"
			. "\tif (\$center !== '') { \$inner .= '<span class=\"ui-btn-center\">' . \$center . '</span>'; }\n"
			. "\tif (\$right !== '') { \$inner .= '<span class=\"ui-btn-right\">' . \$right . '</span>'; }\n"
			. "\treturn {$open} . \$inner . {$close};\n"
			. "}";
	}
}

/* -------------------------------------------------------------------------
 * Container UI Fixtures (Card, Layout, Overlay, Alert, Headline)
 * ------------------------------------------------------------------------- */

class TestCardUi extends Ui
{
	public bool $isContainer = true;
	public string $name = 'ui-card';

	public array $shapeParams = [
		'variant' => 'elevated',
		'linked' => false,
	];

	public array $slotParams = [
		'badge' => '',
		'actions' => '',
		'image' => '',
		'title' => '',
		'subtitle' => '',
		'body' => '',
		'footer' => '',
	];

	protected array $zones = [
		'top' => ['top-left', 'top', 'top-right'],
		'media' => ['media'],
		'center' => ['left', 'center', 'right'],
		'bottom' => ['bottom-left', 'bottom', 'bottom-right'],
	];

	protected array $defaultZones = [
		'badge' => 'top-left',
		'actions' => 'top-right',
		'image' => 'media',
		'title' => 'center',
		'subtitle' => 'center',
		'body' => 'center',
		'footer' => 'bottom-left',
	];

	public static int $resolveShapeCalls = 0;

	protected function configureChildUi(string $item, Ui $child): void
	{
		if ($item === 'title') {
			$child->addClass('ui-card-heading');
		}
	}

	protected function resolveShape(): array
	{
		self::$resolveShapeCalls++;
		$variant = (string) $this->parameters->get('variant', 'elevated');
		$this->addClass(['ui-card', "ui-card-{$variant}"]);
		return parent::resolveShape();
	}

	protected function compileTemplate(array $shape): string
	{
		$open = self::compileOpenTag('article', (string) $shape['classAttr']);
		$zoneMap = $shape['zoneMap'];
		$linked = (bool) ($shape['linked'] ?? false);
		$linkClassAttr = (string) ($shape['slotClassAttrs']['link'] ?? '');
		$linkOpen = self::compileOpenTag('a', $linkClassAttr, 'link');

		$tlExpr = self::compileZoneItemsExpr($zoneMap['top-left'] ?? []);
		$tExpr = self::compileZoneItemsExpr($zoneMap['top'] ?? []);
		$trExpr = self::compileZoneItemsExpr($zoneMap['top-right'] ?? []);
		$mediaExpr = self::compileZoneItemsExpr($zoneMap['media'] ?? []);
		$lExpr = self::compileZoneItemsExpr($zoneMap['left'] ?? []);
		$cExpr = self::compileZoneItemsExpr($zoneMap['center'] ?? []);
		$rExpr = self::compileZoneItemsExpr($zoneMap['right'] ?? []);
		$blExpr = self::compileZoneItemsExpr($zoneMap['bottom-left'] ?? []);
		$bExpr = self::compileZoneItemsExpr($zoneMap['bottom'] ?? []);
		$brExpr = self::compileZoneItemsExpr($zoneMap['bottom-right'] ?? []);

		$linkWrapCode = $linked
			? "\t\$c = {$linkOpen} . \$c . '</a>';\n"
			: '';

		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\t\$html = {$open};\n"
			. "\t\$tl = {$tlExpr}; \$t = {$tExpr}; \$tr = {$trExpr};\n"
			. "\tif (\$tl !== '' || \$t !== '' || \$tr !== '') {\n"
			. "\t\t\$html .= '<header class=\"ui-card-top\">';\n"
			. "\t\tif (\$tl !== '') { \$html .= '<div class=\"ui-card-top-left\">' . \$tl . '</div>'; }\n"
			. "\t\tif (\$t !== '') { \$html .= '<div class=\"ui-card-top-center\">' . \$t . '</div>'; }\n"
			. "\t\tif (\$tr !== '') { \$html .= '<div class=\"ui-card-top-right\">' . \$tr . '</div>'; }\n"
			. "\t\t\$html .= '</header>';\n"
			. "\t}\n"
			. "\t\$media = {$mediaExpr};\n"
			. "\tif (\$media !== '') { \$html .= '<div class=\"ui-card-media\">' . \$media . '</div>'; }\n"
			. "\t\$l = {$lExpr}; \$c = {$cExpr} . \$children; \$r = {$rExpr};\n"
			. $linkWrapCode
			. "\tif (\$l !== '' || \$c !== '' || \$r !== '') {\n"
			. "\t\t\$html .= '<div class=\"ui-card-body\">';\n"
			. "\t\tif (\$l !== '') { \$html .= '<div class=\"ui-card-left\">' . \$l . '</div>'; }\n"
			. "\t\tif (\$c !== '') { \$html .= '<div class=\"ui-card-center\">' . \$c . '</div>'; }\n"
			. "\t\tif (\$r !== '') { \$html .= '<div class=\"ui-card-right\">' . \$r . '</div>'; }\n"
			. "\t\t\$html .= '</div>';\n"
			. "\t}\n"
			. "\t\$bl = {$blExpr}; \$b = {$bExpr}; \$br = {$brExpr};\n"
			. "\tif (\$bl !== '' || \$b !== '' || \$br !== '') {\n"
			. "\t\t\$html .= '<footer class=\"ui-card-bottom\">';\n"
			. "\t\tif (\$bl !== '') { \$html .= '<div class=\"ui-card-bottom-left\">' . \$bl . '</div>'; }\n"
			. "\t\tif (\$b !== '') { \$html .= '<div class=\"ui-card-bottom-center\">' . \$b . '</div>'; }\n"
			. "\t\tif (\$br !== '') { \$html .= '<div class=\"ui-card-bottom-right\">' . \$br . '</div>'; }\n"
			. "\t\t\$html .= '</footer>';\n"
			. "\t}\n"
			. "\treturn \$html . '</article>';\n"
			. "}";
	}
}

class TestLayoutUi extends Ui
{
	public bool $isContainer = true;
	public string $name = 'ui-layout';

	public array $shapeParams = [
		'container' => 'fluid',
	];

	protected array $zones = [
		'header',
		'sidebar-left',
		'main',
		'sidebar-right',
		'footer',
	];

	protected array $defaultZones = [
		'header' => 'header',
		'sidebarLeft' => 'sidebar-left',
		'main' => 'main',
		'sidebarRight' => 'sidebar-right',
		'footer' => 'footer',
	];

	protected function resolveShape(): array
	{
		$container = (string) $this->parameters->get('container', 'fluid');
		$this->addClass(['ui-layout', "ui-layout-{$container}"]);
		return parent::resolveShape();
	}

	protected function compileTemplate(array $shape): string
	{
		$open = self::compileOpenTag('div', (string) $shape['classAttr']);
		$zoneMap = $shape['zoneMap'];

		$headerExpr = self::compileZoneItemsExpr($zoneMap['header'] ?? []);
		$leftExpr = self::compileZoneItemsExpr($zoneMap['sidebar-left'] ?? []);
		$mainExpr = self::compileZoneItemsExpr($zoneMap['main'] ?? []);
		$rightExpr = self::compileZoneItemsExpr($zoneMap['sidebar-right'] ?? []);
		$footerExpr = self::compileZoneItemsExpr($zoneMap['footer'] ?? []);

		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\t\$html = {$open};\n"
			. "\t\$header = {$headerExpr};\n"
			. "\tif (\$header !== '') { \$html .= '<header class=\"ui-layout-header\">' . \$header . '</header>'; }\n"
			. "\t\$left = {$leftExpr};\n"
			. "\t\$main = {$mainExpr} . \$children;\n"
			. "\t\$right = {$rightExpr};\n"
			. "\t\$hasSidebar = (\$left !== '' || \$right !== '');\n"
			. "\t\$html .= '<div class=\"' . (\$hasSidebar ? 'ui-layout-grid has-sidebar' : 'ui-layout-grid no-sidebar') . '\">';\n"
			. "\tif (\$left !== '') { \$html .= '<aside class=\"ui-layout-sidebar-left\">' . \$left . '</aside>'; }\n"
			. "\tif (\$main !== '') { \$html .= '<main class=\"ui-layout-main\">' . \$main . '</main>'; }\n"
			. "\tif (\$right !== '') { \$html .= '<aside class=\"ui-layout-sidebar-right\">' . \$right . '</aside>'; }\n"
			. "\t\$html .= '</div>';\n"
			. "\t\$footer = {$footerExpr};\n"
			. "\tif (\$footer !== '') { \$html .= '<footer class=\"ui-layout-footer\">' . \$footer . '</footer>'; }\n"
			. "\treturn \$html . '</div>';\n"
			. "}";
	}
}

class TestOverlayUi extends Ui
{
	public bool $isContainer = true;
	public string $name = 'ui-overlay';

	public array $shapeParams = [
		'tone' => 'dark',
	];

	protected array $zones = [
		'media',
		'top-left',
		'top-center',
		'top-right',
		'center-left',
		'center',
		'center-right',
		'bottom-left',
		'bottom-center',
		'bottom-right',
	];

	protected array $defaultZones = [
		'image' => 'media',
		'badge' => 'top-left',
		'action' => 'top-right',
		'title' => 'bottom-left',
	];

	protected function resolveShape(): array
	{
		$tone = (string) $this->parameters->get('tone', 'dark');
		$this->addClass(['ui-overlay', "ui-overlay-{$tone}"]);
		return parent::resolveShape();
	}

	protected function compileTemplate(array $shape): string
	{
		$open = self::compileOpenTag('div', (string) $shape['classAttr']);
		$zoneMap = $shape['zoneMap'];
		$mediaExpr = self::compileZoneItemsExpr($zoneMap['media'] ?? []);

		$lines = [
			"static function (array \$s, array \$attrs, string \$children): string {",
			"\t\$html = {$open};",
			"\t\$media = {$mediaExpr};",
			"\tif (\$media !== '') { \$html .= '<div class=\"ui-overlay-media\">' . \$media . '</div>'; }",
		];

		foreach (['top-left', 'top-center', 'top-right', 'center-left', 'center', 'center-right', 'bottom-left', 'bottom-center', 'bottom-right'] as $pos) {
			$expr = self::compileZoneItemsExpr($zoneMap[$pos] ?? []);
			$var = '$z_' . str_replace('-', '_', $pos);
			$lines[] = "\t{$var} = {$expr};";
			$lines[] = "\tif ({$var} !== '') { \$html .= '<div class=\"ui-overlay-pos ui-overlay-{$pos}\">' . {$var} . '</div>'; }";
		}

		$lines[] = "\treturn \$html . \$children . '</div>';";
		$lines[] = "}";

		return implode("\n", $lines);
	}
}

class TestAlertUi extends Ui
{
	public bool $isContainer = true;
	public string $name = 'ui-alert';

	public array $shapeParams = [
		'status' => 'info',
	];

	protected array $zones = ['left', 'center', 'right', 'bottom'];

	protected array $defaultZones = [
		'icon' => 'left',
		'title' => 'center',
		'message' => 'center',
		'dismiss' => 'right',
		'actions' => 'bottom',
	];

	protected function resolveShape(): array
	{
		$status = (string) $this->parameters->get('status', 'info');
		$this->addClass(['ui-alert', "ui-alert-{$status}"]);
		return parent::resolveShape();
	}

	protected function compileTemplate(array $shape): string
	{
		$open = self::compileOpenTag('div', (string) $shape['classAttr']);
		$zoneMap = $shape['zoneMap'];

		$leftExpr = self::compileZoneItemsExpr($zoneMap['left'] ?? []);
		$centerExpr = self::compileZoneItemsExpr($zoneMap['center'] ?? []);
		$rightExpr = self::compileZoneItemsExpr($zoneMap['right'] ?? []);
		$bottomExpr = self::compileZoneItemsExpr($zoneMap['bottom'] ?? []);

		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\t\$html = {$open};\n"
			. "\t\$l = {$leftExpr}; \$c = {$centerExpr} . \$children; \$r = {$rightExpr}; \$b = {$bottomExpr};\n"
			. "\tif (\$l !== '') { \$html .= '<div class=\"ui-alert-left\">' . \$l . '</div>'; }\n"
			. "\tif (\$c !== '') { \$html .= '<div class=\"ui-alert-center\">' . \$c . '</div>'; }\n"
			. "\tif (\$r !== '') { \$html .= '<div class=\"ui-alert-right\">' . \$r . '</div>'; }\n"
			. "\tif (\$b !== '') { \$html .= '<div class=\"ui-alert-bottom\">' . \$b . '</div>'; }\n"
			. "\treturn \$html . '</div>';\n"
			. "}";
	}
}

class TestHeadlineUi extends Ui
{
	public bool $isContainer = true;
	public string $name = 'ui-headline';

	public array $shapeParams = [
		'align' => 'left',
	];

	protected array $zones = ['eyebrow', 'title', 'subtitle', 'actions'];

	protected array $defaultZones = [
		'eyebrow' => 'eyebrow',
		'title' => 'title',
		'subtitle' => 'subtitle',
		'actions' => 'actions',
	];

	protected function resolveShape(): array
	{
		$align = (string) $this->parameters->get('align', 'left');
		$this->addClass(['ui-headline', "ui-headline-{$align}"]);
		return parent::resolveShape();
	}

	protected function compileTemplate(array $shape): string
	{
		$open = self::compileOpenTag('header', (string) $shape['classAttr']);
		$zoneMap = $shape['zoneMap'];

		$eyebrowExpr = self::compileZoneItemsExpr($zoneMap['eyebrow'] ?? []);
		$titleExpr = self::compileZoneItemsExpr($zoneMap['title'] ?? []);
		$subExpr = self::compileZoneItemsExpr($zoneMap['subtitle'] ?? []);
		$actExpr = self::compileZoneItemsExpr($zoneMap['actions'] ?? []);

		return "static function (array \$s, array \$attrs, string \$children): string {\n"
			. "\t\$html = {$open};\n"
			. "\t\$e = {$eyebrowExpr}; \$t = {$titleExpr}; \$sub = {$subExpr}; \$act = {$actExpr};\n"
			. "\tif (\$e !== '') { \$html .= '<div class=\"ui-headline-eyebrow\">' . \$e . '</div>'; }\n"
			. "\tif (\$t !== '') { \$html .= '<div class=\"ui-headline-title\">' . \$t . '</div>'; }\n"
			. "\tif (\$sub !== '') { \$html .= '<p class=\"ui-headline-subtitle\">' . \$sub . '</p>'; }\n"
			. "\tif (\$act !== '') { \$html .= '<div class=\"ui-headline-actions\">' . \$act . '</div>'; }\n"
			. "\treturn \$html . \$children . '</header>';\n"
			. "}";
	}
}

/* -------------------------------------------------------------------------
 * Stateful Component Fixture for Tier 1 (Schema) & Tier 2 (Render Cache)
 * ------------------------------------------------------------------------- */

class TestCachedCounterComponent extends Component
{
	public int $count = 0;
	public string $label = 'Visitors';

	public static int $renderCalls = 0;

	public function increment(int $step = 1): void
	{
		$this->count += $step;
	}

	public function queryPreview(string $prefix = 'Count'): string
	{
		return "<span class=\"preview\">{$prefix}: {$this->count}</span>";
	}

	public function triggerInvalid(): void
	{
		$this->validationError('#counter-err', '#counter-form', 422);
	}

	public function render(): string
	{
		self::$renderCalls++;
		$config = $this->wire()->config;
		if ($config && $config->scripts) {
			$config->scripts->add('/site/modules/Htmx/resources/counter-widget.js');
		}
		if ($config) {
			$config->js('counterWidget', ['step' => 1]);
		}

		$card = TestCardUi::make(['variant' => 'elevated'])
			->setAttribute('id', $this->id)
			->slot('badge', TestBadgeUi::make(['label' => (string) $this->count, 'variant' => 'primary']))
			->slot('title', TestTitleUi::make(['text' => $this->label]))
			->slot(
				'actions',
				TestButtonUi::make(['label' => '+1'])
					->action($this->requestUrl())
					->method('post')
					->actionTrigger('increment')
			)
			->addChild($this->renderStatePayload());

		return (string) $card;
	}
}

/* -------------------------------------------------------------------------
 * Test Setup & Teardown
 * ------------------------------------------------------------------------- */

	beforeEach(function (): void {
	if (!class_exists(HtmxCacheClearCommand::class)) {
		require_once dirname(__DIR__, 2) . '/commands/HtmxCacheClearCommand.php';
	}
	$this->tempCacheDir = sys_get_temp_dir() . '/pw-htmx-cache-test-' . getmypid() . '-' . uniqid();
	$this->htmxCache = new HtmxCache($this->tempCacheDir, true, 64);

	$wire = wire();
	if ($wire) {
		$wire->wire('htmxCache', $this->htmxCache);
		$htmx = $wire->htmx;
		if (is_object($htmx)) {
			$htmx->cache = $this->htmxCache;
			$htmx->response = new Response();
			$htmx->fragment = new Fragment();
		}
	}

	TestTitleUi::$resolveShapeCalls = 0;
	TestBadgeUi::$resolveShapeCalls = 0;
	TestImageUi::$resolveShapeCalls = 0;
	TestButtonUi::$resolveShapeCalls = 0;
	TestCardUi::$resolveShapeCalls = 0;
	TestCachedCounterComponent::$renderCalls = 0;
});

afterEach(function (): void {
	if (isset($this->htmxCache) && $this->htmxCache instanceof HtmxCache) {
		$this->htmxCache->clear();
	}
	if (isset($this->tempCacheDir) && is_dir($this->tempCacheDir)) {
		@rmdir($this->tempCacheDir);
	}
});

/* -------------------------------------------------------------------------
 * 1. Value Gate & Short-Circuiting Tests
 * ------------------------------------------------------------------------- */

describe('Value Gate (hasValue / renderIfHasValue)', function (): void {
	it('returns empty string and writes zero cache files when atomic UI elements have no value', function (): void {
		$emptyTitle = TestTitleUi::make(['text' => '   ']);
		$emptyBadge = TestBadgeUi::make(['label' => '', 'icon' => '']);
		$emptyImage = TestImageUi::make(['src' => '']);
		$emptyButton = TestButtonUi::make(['label' => '', 'icon' => '', 'trailing' => '']);

		expect($emptyTitle->hasValue())->toBeFalse()
			->and((string) $emptyTitle)->toBe('')
			->and($emptyBadge->hasValue())->toBeFalse()
			->and((string) $emptyBadge)->toBe('')
			->and($emptyImage->hasValue())->toBeFalse()
			->and((string) $emptyImage)->toBe('')
			->and($emptyButton->hasValue())->toBeFalse()
			->and((string) $emptyButton)->toBe('');

		$stats = $this->htmxCache->stats();
		expect($stats['uiFiles'])->toBe(0)
			->and(TestTitleUi::$resolveShapeCalls)->toBe(0)
			->and(TestBadgeUi::$resolveShapeCalls)->toBe(0)
			->and(TestImageUi::$resolveShapeCalls)->toBe(0)
			->and(TestButtonUi::$resolveShapeCalls)->toBe(0);
	});

	it('short-circuits an entire Container Card when all child UI elements have empty values', function (): void {
		$card = TestCardUi::make()
			->slot('badge', TestBadgeUi::make(['label' => '']))
			->slot('image', TestImageUi::make(['src' => '']))
			->slot('title', TestTitleUi::make(['text' => '']))
			->slot('actions', TestButtonUi::make(['label' => '']));

		expect($card->hasValue())->toBeFalse()
			->and((string) $card)->toBe('');

		$stats = $this->htmxCache->stats();
		expect($stats['uiFiles'])->toBe(0)
			->and(TestCardUi::$resolveShapeCalls)->toBe(0)
			->and(TestTitleUi::$resolveShapeCalls)->toBe(0);
	});

	it('omits empty child UI elements and empty zone wrappers while rendering non-empty siblings inside Card', function (): void {
		$card = TestCardUi::make()
			->slot('badge', TestBadgeUi::make(['label' => ''])) // empty -> omitted!
			->slot('actions', TestButtonUi::make(['label' => ''])) // empty -> top header omitted!
			->slot('image', TestImageUi::make(['src' => ''])) // empty -> media wrapper omitted!
			->slot('title', TestTitleUi::make(['text' => 'Active Product'])) // non-empty!
			->slot('footer', ''); // empty -> bottom footer omitted!

		$html = (string) $card;

		expect($html)->toContain('<article class="ui-card ui-card-elevated">')
			->and($html)->toContain('<div class="ui-card-body"><div class="ui-card-center"><h3 class="ui-title ui-title-md ui-card-heading">Active Product</h3></div></div>')
			->and($html)->not->toContain('ui-card-top')
			->and($html)->not->toContain('ui-card-media')
			->and($html)->not->toContain('ui-card-bottom')
			->and($html)->not->toContain('ui-badge');

		// Only Card and Title should have compiled cache files; Badge, Image, and Button must have 0 files!
		expect(TestCardUi::$resolveShapeCalls)->toBe(1)
			->and(TestTitleUi::$resolveShapeCalls)->toBe(1)
			->and(TestBadgeUi::$resolveShapeCalls)->toBe(0)
			->and(TestImageUi::$resolveShapeCalls)->toBe(0)
			->and(TestButtonUi::$resolveShapeCalls)->toBe(0)
			->and($this->htmxCache->stats()['uiFiles'])->toBe(2);
	});

	it('filters out empty child UI items inside an array slot while compiling distinct shapes for non-empty siblings', function (): void {
		$card = TestCardUi::make()
			->slot('title', TestTitleUi::make(['text' => 'Card With Action Array']))
			->slot('actions', [
				TestButtonUi::make(['label' => 'Save', 'variant' => 'primary']),
				TestButtonUi::make(['label' => '', 'icon' => '']), // empty -> must be skipped by Value Gate!
				TestButtonUi::make(['label' => 'Cancel', 'variant' => 'secondary'])->addClass('btn-outline'),
			]);

		$html = (string) $card;

		expect($html)->toContain('<button class="ui-btn ui-btn-primary"><span class="ui-btn-center">Save</span></button>')
			->and($html)->toContain('<button class="ui-btn ui-btn-secondary btn-outline"><span class="ui-btn-center">Cancel</span></button>')
			->and(TestButtonUi::$resolveShapeCalls)->toBe(2);
	});
});

/* -------------------------------------------------------------------------
 * 2. Shape Compilation, Child Class Invalidation & Runtime Attribute Isolation
 * ------------------------------------------------------------------------- */

describe('Two-Phase Shape Compilation & Child Class Isolation', function (): void {
	it('compiles 1 shape file for 50 cards with different titles, badges, hx-vals, and data-* attributes', function (): void {
		for ($i = 1; $i <= 50; $i++) {
			$card = TestCardUi::make(['variant' => 'elevated'])
				->setAttribute('id', "card-{$i}")
				->data('item-id', $i)
				->action('/hx/req')
				->method('post')
				->vals(['itemId' => $i])
				->slot('badge', TestBadgeUi::make(['label' => "Badge #{$i}", 'variant' => 'info']))
				->slot('title', TestTitleUi::make(['text' => "Card Title <{$i}>"]))
				->slot('body', "Body content {$i}");

			$html = (string) $card;

			expect($html)->toContain("id=\"card-{$i}\"")
				->and($html)->toContain("data-item-id=\"{$i}\"")
				->and($html)->toContain("Badge #{$i}")
				->and($html)->toContain("Card Title &lt;{$i}&gt;")
				->and($html)->toContain("Body content {$i}");
		}

		$stats = $this->htmxCache->stats();

		// 1 shape file for Card, 1 for Badge, 1 for Title = 3 total files for 150 rendered UI instances!
		expect($stats['uiFiles'])->toBe(3)
			->and(TestCardUi::$resolveShapeCalls)->toBe(1)
			->and(TestBadgeUi::$resolveShapeCalls)->toBe(1)
			->and(TestTitleUi::$resolveShapeCalls)->toBe(1)
			->and($stats['metrics']['uiHits'])->toBe((50 - 1) * 3);
	});

	it('generates a new child shape cache file when a child UI element inside Card changes its class or variant', function (): void {
		$card1 = TestCardUi::make()
			->slot('title', TestTitleUi::make(['text' => 'Standard Heading']));

		$card2 = TestCardUi::make()
			->slot('title', TestTitleUi::make(['text' => 'Highlighted Heading'])->addClass('text-highlight'));

		$html1 = (string) $card1;
		$html2 = (string) $card2;

		expect($html1)->toContain('<h3 class="ui-title ui-title-md ui-card-heading">Standard Heading</h3>')
			->and($html2)->toContain('<h3 class="ui-title ui-title-md text-highlight ui-card-heading">Highlighted Heading</h3>');

		// Card resolved its shape once (1 file); Title resolved 2 distinct shapes (2 files)!
		expect(TestCardUi::$resolveShapeCalls)->toBe(1)
			->and(TestTitleUi::$resolveShapeCalls)->toBe(2)
			->and($this->htmxCache->stats()['uiFiles'])->toBe(3);
	});

	it('supports named sub-element AttributeBags (attrFor) without exploding shape files', function (): void {
		for ($i = 1; $i <= 5; $i++) {
			$card = TestCardUi::make(['linked' => true])
				->slot('title', TestTitleUi::make(['text' => "Item {$i}"]));

			$card->for('link')
				->addClass('ui-card-stretch-link')
				->set('href', "/products/{$i}")
				->hx('action', "/products/{$i}/preview")
				->hx('method', 'get');

			$html = (string) $card;
			expect($html)->toContain('<a class="ui-card-stretch-link" href="/products/' . $i . '" hx-action="/products/' . $i . '/preview" hx-method="get">');
		}

		expect(TestCardUi::$resolveShapeCalls)->toBe(1);
	});
});

/* -------------------------------------------------------------------------
 * 3. Component-Specific Zone & Slot Engine Across 5 Architectural Archetypes
 * ------------------------------------------------------------------------- */

describe('Component-Defined Zones & Multi-Component Composition', function (): void {
	it('recompiles Card shape when an item is moved to a different zone or reordered via place()', function (): void {
		$defaultCard = TestCardUi::make()
			->slot('badge', TestBadgeUi::make(['label' => 'NEW']))
			->slot('title', TestTitleUi::make(['text' => 'Title']))
			->slot('subtitle', 'Subtitle');

		$rearrangedCard = TestCardUi::make()
			->slot('badge', TestBadgeUi::make(['label' => 'NEW']))
			->slot('title', TestTitleUi::make(['text' => 'Title']))
			->slot('subtitle', 'Subtitle')
			->place('badge', 'bottom-right')
			->place('subtitle', 'center', 'before:title');

		$htmlDefault = (string) $defaultCard;
		$htmlRearranged = (string) $rearrangedCard;

		// Default: badge is in top-left, title is before subtitle
		expect($htmlDefault)->toContain('<div class="ui-card-top-left"><span class="ui-badge ui-badge-default">NEW</span></div>')
			->and($htmlDefault)->toContain('<h3 class="ui-title ui-title-md ui-card-heading">Title</h3>Subtitle')
			->and($htmlDefault)->not->toContain('ui-card-bottom-right');

		// Rearranged: top header is omitted (empty!), subtitle is before title, badge is in bottom-right
		expect($htmlRearranged)->not->toContain('ui-card-top')
			->and($htmlRearranged)->toContain('Subtitle<h3 class="ui-title ui-title-md ui-card-heading">Title</h3>')
			->and($htmlRearranged)->toContain('<div class="ui-card-bottom-right"><span class="ui-badge ui-badge-default">NEW</span></div>');

		expect(TestCardUi::$resolveShapeCalls)->toBe(2);
	});

	it('throws InvalidArgumentException when placing an item into an undeclared zone', function (): void {
		expect(fn() => TestButtonUi::make()->place('icon', 'top-left'))
			->toThrow(InvalidArgumentException::class, 'Invalid zone "top-left"');
	});

	it('renders Layout with sidebar when sidebar zone has content, and collapses sidebar when sidebar child UI is empty', function (): void {
		// 1. Layout with active left sidebar
		$layoutWithSidebar = TestLayoutUi::make()
			->slot('header', TestHeadlineUi::make()->slot('title', TestTitleUi::make(['text' => 'Dashboard'])))
			->slot('sidebarLeft', TestCardUi::make()->slot('title', TestTitleUi::make(['text' => 'Navigation'])))
			->slot('main', TestCardUi::make()->slot('title', TestTitleUi::make(['text' => 'Main Content'])));

		$htmlWithSidebar = (string) $layoutWithSidebar;
		expect($htmlWithSidebar)->toContain('ui-layout-grid has-sidebar')
			->and($htmlWithSidebar)->toContain('<aside class="ui-layout-sidebar-left">')
			->and($htmlWithSidebar)->toContain('Navigation')
			->and($htmlWithSidebar)->toContain('<main class="ui-layout-main">');

		// 2. Layout where sidebarLeft contains an empty Card (whose Title is empty!)
		$layoutWithoutSidebar = TestLayoutUi::make()
			->slot('sidebarLeft', TestCardUi::make()->slot('title', TestTitleUi::make(['text' => ''])))
			->slot('main', TestCardUi::make()->slot('title', TestTitleUi::make(['text' => 'Full Width Content'])));

		$htmlWithoutSidebar = (string) $layoutWithoutSidebar;
		expect($htmlWithoutSidebar)->toContain('ui-layout-grid no-sidebar')
			->and($htmlWithoutSidebar)->not->toContain('<aside')
			->and($htmlWithoutSidebar)->toContain('Full Width Content');
	});

	it('renders Overlay 9-point spatial zones, Alert zones, and Headline zones with atomic UI children', function (): void {
		$overlay = TestOverlayUi::make(['tone' => 'gradient'])
			->slot('image', TestImageUi::make(['src' => '/assets/hero.jpg', 'alt' => 'Hero']))
			->slot('badge', TestBadgeUi::make(['label' => 'LIVE', 'variant' => 'danger']))
			->slot('action', TestButtonUi::make(['icon' => 'heart']))
			->slot('title', TestTitleUi::make(['tag' => 'h2', 'text' => 'Spatial Overlay']));

		$overlayHtml = (string) $overlay;
		expect($overlayHtml)->toContain('ui-overlay-gradient')
			->and($overlayHtml)->toContain('<div class="ui-overlay-media"><img class="ui-image ui-image-auto" src="/assets/hero.jpg" alt="Hero"></div>')
			->and($overlayHtml)->toContain('<div class="ui-overlay-pos ui-overlay-top-left"><span class="ui-badge ui-badge-danger">LIVE</span></div>')
			->and($overlayHtml)->toContain('<div class="ui-overlay-pos ui-overlay-top-right"><button class="ui-btn ui-btn-primary"><span class="ui-btn-left">heart</span></button></div>')
			->and($overlayHtml)->toContain('<div class="ui-overlay-pos ui-overlay-bottom-left"><h2 class="ui-title ui-title-md">Spatial Overlay</h2></div>');

		$alert = TestAlertUi::make(['status' => 'warning'])
			->slot('icon', 'bell')
			->slot('title', TestTitleUi::make(['tag' => 'h4', 'text' => 'Quota Warning']))
			->slot('message', '85% used')
			->slot('dismiss', TestButtonUi::make(['label' => 'Dismiss']));

		$alertHtml = (string) $alert;
		expect($alertHtml)->toContain('ui-alert-warning')
			->and($alertHtml)->toContain('<div class="ui-alert-left">bell</div>')
			->and($alertHtml)->toContain('<div class="ui-alert-center"><h4 class="ui-title ui-title-md">Quota Warning</h4>85% used</div>')
			->and($alertHtml)->toContain('<div class="ui-alert-right"><button class="ui-btn ui-btn-primary"><span class="ui-btn-center">Dismiss</span></button></div>');
	});
});

/* -------------------------------------------------------------------------
 * 4. Memoization, mtime Self-Pruning & LRU Cap
 * ------------------------------------------------------------------------- */

describe('HtmxCache Memoization, mtime Self-Pruning & LRU Eviction', function (): void {
	it('memoizes full output when memo(true) is enabled on repeated identical leaf UI elements', function (): void {
		$b1 = (string) TestBadgeUi::make(['label' => 'PRO', 'variant' => 'success'])->memo(true);
		$b2 = (string) TestBadgeUi::make(['label' => 'PRO', 'variant' => 'success'])->memo(true);

		expect($b1)->toBe($b2)
			->and($this->htmxCache->stats()['metrics']['memoHits'])->toBe(1);
	});

	it('automatically prunes stale mtime files and enforces maxShapesPerClass LRU cap', function (): void {
		$this->htmxCache->setMaxShapesPerClass(4);

		// Create a fake stale mtime file in TestBadgeUi's cache folder
		$badgeDir = $this->htmxCache->getUiClassDir(TestBadgeUi::class);
		@mkdir($badgeDir, 0755, true);
		$staleFile = $badgeDir . 'TestBadgeUi_1000000000_deadbeef.php';
		file_put_contents($staleFile, "<?php return static fn() => '';");
		expect(is_file($staleFile))->toBeTrue();

		// Compile 6 distinct shapes (different classes) for TestBadgeUi
		for ($i = 1; $i <= 6; $i++) {
			$badge = TestBadgeUi::make(['label' => 'Tag', 'variant' => "v{$i}"]);
			expect((string) $badge)->toContain("ui-badge-v{$i}");
		}

		// Stale mtime file must be auto-removed, and total shape files for TestBadgeUi must be capped at 4!
		expect(is_file($staleFile))->toBeFalse();
		$remainingFiles = glob($badgeDir . '*.php') ?: [];
		expect(count($remainingFiles))->toBeLessThanOrEqual(4);
	});

	it('reloads compiled UI shapes and persisted memos from disk across simulated HTTP requests (resetMemory)', function (): void {
		$card1 = TestCardUi::make(['variant' => 'elevated'])
			->slot('badge', TestBadgeUi::make(['label' => 'DISK', 'variant' => 'info'])->memo(true, true))
			->slot('title', TestTitleUi::make(['text' => 'First Request']));

		$html1 = (string) $card1;
		expect($html1)->toContain('First Request')
			->and(TestCardUi::$resolveShapeCalls)->toBe(1)
			->and(TestTitleUi::$resolveShapeCalls)->toBe(1)
			->and(TestBadgeUi::$resolveShapeCalls)->toBe(1);

		// Simulate a brand new HTTP request by clearing all in-memory closure and memo maps
		$this->htmxCache->resetMemory();
		TestCardUi::$resolveShapeCalls = 0;
		TestTitleUi::$resolveShapeCalls = 0;
		TestBadgeUi::$resolveShapeCalls = 0;

		$card2 = TestCardUi::make(['variant' => 'elevated'])
			->slot('badge', TestBadgeUi::make(['label' => 'DISK', 'variant' => 'info'])->memo(true, true))
			->slot('title', TestTitleUi::make(['text' => 'Second Request From Disk Closure']));

		$html2 = (string) $card2;

		// Zero shape recompilations on the second request: loaded directly from disk .php files!
		expect($html2)->toContain('Second Request From Disk Closure')
			->and($html2)->toContain('DISK')
			->and(TestCardUi::$resolveShapeCalls)->toBe(0)
			->and(TestTitleUi::$resolveShapeCalls)->toBe(0)
			->and(TestBadgeUi::$resolveShapeCalls)->toBe(0)
			->and($this->htmxCache->stats()['metrics']['uiHits'])->toBe(2)
			->and($this->htmxCache->stats()['metrics']['memoHits'])->toBe(1);
	});
});

/* -------------------------------------------------------------------------
 * 5. Component Tier 1 (Compiled Schema) & Tier 2 (Tokenized Render Cache)
 * ------------------------------------------------------------------------- */

describe('Component Compiled Reflection Schema & Tokenized Render Cache', function (): void {
	it('compiles Component reflection schema to disk and coerces properties + actions without runtime reflection loops', function (): void {
		$cmp = new TestCachedCounterComponent();
		$cmp->fill(['count' => '42', 'label' => 'Active Users']);

		expect($cmp->count)->toBe(42)
			->and($cmp->label)->toBe('Active Users');

		$stats = $this->htmxCache->stats();
		expect($stats['componentFiles'])->toBeGreaterThanOrEqual(1);

		// Hydrate round-trip using compiled schema
		$payloadInput = $cmp->renderStatePayload();
		preg_match('/name="hx__state" value="([^"]+)"/', $payloadInput, $m);
		expect($m[1] ?? '')->not->toBe('');

		$restored = new TestCachedCounterComponent();
		$decoded = Component::decodeSignedPayload(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), $cmp->getHmacSalt());
		$restored->setPreDecodedState($decoded)->hydrate();

		expect($restored->count)->toBe(42)
			->and($restored->label)->toBe('Active Users');
	});

	it('caches Component render output with tokenized IDs, fresh HMAC state payloads, and replayed config assets', function (): void {
		$cmp1 = (new TestCachedCounterComponent())
			->enableRenderCache(true, 3600)
			->fill(['count' => 10, 'label' => 'Downloads']);

		$html1 = (string) $cmp1;

		// Second instance with identical state but different auto-generated $id!
		$cmp2 = (new TestCachedCounterComponent())
			->enableRenderCache(true, 3600)
			->fill(['count' => 10, 'label' => 'Downloads']);

		$html2 = (string) $cmp2;

		// render() was called ONLY ONCE, second instance hit Component Render Cache!
		expect(TestCachedCounterComponent::$renderCalls)->toBe(1)
			->and($this->htmxCache->stats()['metrics']['renderHits'])->toBe(1);

		// Yet each instance has its own unique ID in both DOM and signed hx__state payload!
		expect($cmp1->id)->not->toBe($cmp2->id)
			->and($html1)->toContain('id="' . $cmp1->id . '"')
			->and($html2)->toContain('id="' . $cmp2->id . '"');

		preg_match('/name="hx__state" value="([^"]+)"/', $html1, $m1);
		preg_match('/name="hx__state" value="([^"]+)"/', $html2, $m2);

		$decoded1 = Component::decodeSignedPayload(html_entity_decode($m1[1], ENT_QUOTES, 'UTF-8'), $cmp1->getHmacSalt());
		$decoded2 = Component::decodeSignedPayload(html_entity_decode($m2[1], ENT_QUOTES, 'UTF-8'), $cmp2->getHmacSalt());

		expect($decoded1['__id'])->toBe($cmp1->id)
			->and($decoded2['__id'])->toBe($cmp2->id)
			->and($decoded1['count'])->toBe(10)
			->and($decoded2['count'])->toBe(10);
	});

	it('bypasses Component render cache when a validationError or OOB fragment is emitted', function (): void {
		$cmp = (new TestCachedCounterComponent())
			->enableRenderCache(true, 3600)
			->fill(['count' => 99, 'label' => 'Invalid Run']);

		$cmp->triggerInvalid();
		$html = (string) $cmp;

		expect($html)->toContain('Invalid Run')
			->and($this->htmxCache->stats()['metrics']['renderHits'])->toBe(0);

		// Subsequent clean render should still execute render() because the 422 response was not cached
		$cleanCmp = (new TestCachedCounterComponent())
			->enableRenderCache(true, 3600)
			->fill(['count' => 99, 'label' => 'Invalid Run']);
		(string) $cleanCmp;

		expect(TestCachedCounterComponent::$renderCalls)->toBe(2);
	});

	it('expires cached Component renders when TTL elapses and reloads compiled schema from disk after resetMemory', function (): void {
		$cmp = (new TestCachedCounterComponent())
			->enableRenderCache(true, 3600)
			->fill(['count' => 7, 'label' => 'TTL Check']);

		(string) $cmp;
		expect(TestCachedCounterComponent::$renderCalls)->toBe(1);

		// Simulate disk-only schema lookup and expired TTL (-1s)
		$this->htmxCache->resetMemory();
		$expired = $this->htmxCache->getComponentRender(TestCachedCounterComponent::class, ['any' => 'key'], -1);
		expect($expired)->toBeNull();

		// Force the existing render cache file's createdAt timestamp into the past
		$cmpDir = $this->htmxCache->getComponentClassDir(TestCachedCounterComponent::class);
		foreach (glob($cmpDir . '*_render_*.php') ?: [] as $rf) {
			$data = include $rf;
			if (is_array($data)) {
				$data['createdAt'] = time() - 7200;
				file_put_contents($rf, "<?php\nreturn " . var_export($data, true) . ";\n");
			}
		}

		$cmp2 = (new TestCachedCounterComponent())
			->enableRenderCache(true, 60)
			->fill(['count' => 7, 'label' => 'TTL Check']);

		(string) $cmp2;
		// Because the cached render entry expired, render() was invoked again and schema hit came from disk!
		expect(TestCachedCounterComponent::$renderCalls)->toBe(2)
			->and($this->htmxCache->stats()['metrics']['schemaHits'])->toBeGreaterThanOrEqual(1);
	});
});

/* -------------------------------------------------------------------------
 * 6. CLI Command (htmx:cache:clear) Tests
 * ------------------------------------------------------------------------- */

describe('HtmxCacheClearCommand CLI', function (): void {
	it('reports stats, prunes, and clears compiled cache files with --json output', function (): void {
		// Populate cache with 1 Card + 1 Title
		(string) TestCardUi::make()->slot('title', TestTitleUi::make(['text' => 'CLI Test']));
		expect($this->htmxCache->stats()['uiFiles'])->toBe(2);

		$cmd = new HtmxCacheClearCommand();

		// 1. --stats --json
		$statsOut = new BufferedOutput();
		$code1 = $cmd->run(new ArrayInput(['--stats' => true, '--json' => true]), $statsOut);
		$json1 = json_decode($statsOut->fetch(), true);
		expect($code1)->toBe(0)
			->and($json1['ok'])->toBeTrue()
			->and($json1['data']['stats']['uiFiles'])->toBe(2);

		// 2. --class=TestTitleUi --json
		$classOut = new BufferedOutput();
		$code2 = $cmd->run(new ArrayInput(['--class' => 'TestTitleUi', '--json' => true]), $classOut);
		$json2 = json_decode($classOut->fetch(), true);
		expect($code2)->toBe(0)
			->and($json2['ok'])->toBeTrue()
			->and($json2['data']['removedFiles'])->toBe(1)
			->and($json2['data']['stats']['uiFiles'])->toBe(1);

		// 3. Full clear --json
		$clearOut = new BufferedOutput();
		$code3 = $cmd->run(new ArrayInput(['--json' => true]), $clearOut);
		$json3 = json_decode($clearOut->fetch(), true);
		expect($code3)->toBe(0)
			->and($json3['ok'])->toBeTrue()
			->and($json3['data']['stats']['uiFiles'])->toBe(0);
	});

	it('includes updated HtmxUi stub with shape compilation, zones, and value gate', function (): void {
		$stubPath = dirname(__DIR__, 2) . '/commands/stubs/htmx-ui.stub';
		$stub = (string) file_get_contents($stubPath);

		expect($stub)->toContain('$shapeParams')
			->and($stub)->toContain('$slotParams')
			->and($stub)->toContain('$zones')
			->and($stub)->toContain('$valueGateKeys')
			->and($stub)->toContain('protected function resolveShape(): array')
			->and($stub)->toContain('protected function compileTemplate(array $shape): string');
	});
});
