<?php

declare(strict_types=1);

namespace Htmx\Component;

use Totoglu\Htmx\Component;

class TestAmnesiacComponent extends Component
{
	public int $counter = 0;

	public function mount(): void
	{
		$this->counter = 10;
	}

	public function fill(array $props): self
	{
		if (isset($props['start'])) {
			$this->counter = (int) $props['start'];
		}

		return parent::fill($props);
	}

	public function increment(): void
	{
		$this->counter++;

		if ($this->htmx) {
			$this->htmx->response->trigger('countUpdated', ['counter' => $this->counter]);
			$msg = htmlspecialchars($this->__('Counter is now'), ENT_QUOTES, 'UTF-8');
			$this->htmx->fragment->addPartial('#some-other-div', "<div id='some-other-div'>{$msg}: {$this->counter}</div>", 'outerMorph');
		}
	}

	public function render(): string
	{
		$currentCount = (int) $this->counter;
		$buttonLabel = htmlspecialchars($this->__('Increment'), ENT_QUOTES, 'UTF-8');
		$id = 'hxc_' . $this->id();

		return <<<HTML
		<div id="{$id}">
			Current count: {$currentCount}

			<form hx-action="{$this->requestUrl()}" hx-method="post" hx-target="#{$id}" hx-swap="outerMorph">
				{$this->renderStatePayload()}
				<button type="submit" name="__htmx_action" value="increment" class="uk-button uk-button-primary">
					{$buttonLabel}
				</button>
			</form>
		</div>
		HTML;
	}
}
