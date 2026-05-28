<?php

namespace App\Livewire;

use App\Services\RegexTesterService;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;
use Livewire\Component;

class RegexTester extends Component
{
    public string $pattern = '';

    public string $replacement = '';

    public string $samples = '';

    public string $flags = 'ui';

    public string $samplesContext = '';

    /** @var array<int, array{input: string, matches: bool, output: string, error: ?string}> */
    public array $results = [];

    public bool $tested = false;

    public function mount(string $flags = 'ui', string $samplesContext = ''): void
    {
        $this->flags = $flags;
        $this->samplesContext = $samplesContext;
    }

    public function test(): void
    {
        $this->results = RegexTesterService::test(
            $this->pattern,
            $this->flags,
            $this->replacement,
            RegexTesterService::normalizeSamples($this->samples),
        );
        $this->tested = true;
    }

    public function loadSamples(): void
    {
        $samples = RegexTesterService::fetchSamplesForContext($this->samplesContext, auth()->id());
        $this->samples = implode("\n", $samples->toArray());
    }

    public function getRenderedResults(): HtmlString
    {
        return RegexTesterService::renderResults($this->results, true);
    }

    public function render(): View
    {
        return view('livewire.regex-tester');
    }
}
