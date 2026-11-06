<?php

declare(strict_types=1);

namespace Totoglu\Htmx\Commands;

class MakeHtmxUiCommand extends MakeHtmxComponentCommand
{
	protected string $commandName = 'make:htmx:ui';
	protected string $commandDescription = 'Scaffold a new Htmx Ui element.';
	protected string $elementLabel = 'Ui element';
	protected string $argumentDescription = 'The name of the Ui element (e.g. CardHeader or Forms/Input)';
	protected string $configProperty = 'uiPath';
	protected string $defaultDir = 'ui';
	protected string $rootNamespace = 'Htmx\\Ui';
	protected string $stubFilename = 'htmx-ui.stub';
}
