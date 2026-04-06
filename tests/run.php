<?php

declare(strict_types=1);

namespace ProcessWire;

use Htmx\Component\Test1ClickCounter;
use Htmx\Component\Test2ClickCounterUi;
use Htmx\Component\Test3Title;
use Htmx\Component\Test4Headline;
use Htmx\Component\Test5Summary;
use Htmx\Component\Test6Content;
use Htmx\Component\Test7Button;
use Htmx\Component\Test8MultiField;
use Htmx\Component\Test9Dependency;
use Htmx\Component\Test10Validation;

$pageID = null;

$htmx = wire('htmx');

$output = "<div class='uk-container uk-margin-large-top uk-margin-large-bottom'>";
$output .= "<h2>ProcessWire HTMX Experimental Test Suite</h2>";
$output .= "<p class='uk-text-meta'>These 10 components test different rendering and payload hydration scenarios.</p>";

$output .= "<div class='uk-child-width-1-2@m uk-grid-match' uk-grid>";

$output .= "<div>" . $htmx->renderComponent(Test1ClickCounter::class) . "</div>";
$output .= "<div>" . $htmx->renderComponent(Test2ClickCounterUi::class) . "</div>";

$testPage = is_int($pageID) ? wire('pages')->get($pageID) : new NullPage();
if ($testPage->id) {
    $output .= "<div>" . $htmx->renderComponent(Test3Title::class, ['pageId' => $testPage->id]) . "</div>";
    $output .= "<div>" . $htmx->renderComponent(Test4Headline::class, ['pageId' => $testPage->id]) . "</div>";
    $output .= "<div>" . $htmx->renderComponent(Test5Summary::class, ['pageId' => $testPage->id]) . "</div>";
    $output .= "<div>" . $htmx->renderComponent(Test6Content::class, ['pageId' => $testPage->id]) . "</div>";
    $output .= "<div>" . $htmx->renderComponent(Test7Button::class) . "</div>";
    $output .= "<div>" . $htmx->renderComponent(Test8MultiField::class, ['pageId' => $testPage->id]) . "</div>";
    $output .= "<div>" . $htmx->renderComponent(Test9Dependency::class, ['testPage' => $testPage]) . "</div>";
    $output .= "<div>" . $htmx->renderComponent(Test10Validation::class) . "</div>";
}

$output .= "</div></div>";
