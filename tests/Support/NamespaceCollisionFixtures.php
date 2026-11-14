<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Tests\NsAlpha {
	use Totoglu\Htmx\Ui;

	class SharedWidgetUi extends Ui
	{
		public array $shapeParams = ['variant' => 'alpha'];

		protected function compileTemplate(array $shape): string
		{
			$v = htmlspecialchars((string) ($shape['variant'] ?? 'alpha'), ENT_QUOTES, 'UTF-8');
			return "static function (array \$s, array \$attrs, string \$children): string {\n"
				. "\treturn '<span class=\"ns-alpha-{$v}\"' . (\$attrs['root'] ?? '') . '>' . \$children . '</span>';\n"
				. "}";
		}
	}
}

namespace Totoglu\Htmx\Tests\NsBeta {
	use Totoglu\Htmx\Ui;

	class SharedWidgetUi extends Ui
	{
		public array $shapeParams = ['variant' => 'beta'];

		protected function compileTemplate(array $shape): string
		{
			$v = htmlspecialchars((string) ($shape['variant'] ?? 'beta'), ENT_QUOTES, 'UTF-8');
			return "static function (array \$s, array \$attrs, string \$children): string {\n"
				. "\treturn '<div class=\"ns-beta-{$v}\"' . (\$attrs['root'] ?? '') . '>' . \$children . '</div>';\n"
				. "}";
		}
	}
}
