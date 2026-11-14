<?php

namespace VentureDrake\LaravelCrm\Livewire\Features;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Mary\Traits\Toast;
use VentureDrake\LaravelCrm\Models\Feature;
use VentureDrake\LaravelCrm\Models\FeatureView;
use VentureDrake\LaravelCrm\Models\FeatureVote;
use VentureDrake\LaravelCrm\Support\CrmDateTime;

class FeatureShow extends Component
{
    use AuthorizesRequests, Toast;

    public Feature $feature;

    public string $chartPeriod = 'last_30_days';

    public array $votesChart = [];

    public array $viewsChart = [];

    public function mount(Feature $feature)
    {
        $this->feature = $feature;
        $this->votesChart = $this->buildVotesChart();
        $this->viewsChart = $this->buildViewsChart();
    }

    public function updatedChartPeriod(): void
    {
        $this->votesChart = $this->buildVotesChart();
        $this->viewsChart = $this->buildViewsChart();
    }

    public function chartPeriodOptions(): array
    {
        return [
            ['id' => 'today', 'name' => ucfirst(__('laravel-crm::lang.today'))],
            ['id' => 'yesterday', 'name' => ucfirst(__('laravel-crm::lang.yesterday'))],
            ['id' => 'last_7_days', 'name' => __('laravel-crm::lang.last_x_days', ['days' => 7])],
            ['id' => 'last_30_days', 'name' => __('laravel-crm::lang.last_x_days', ['days' => 30])],
            ['id' => 'last_90_days', 'name' => __('laravel-crm::lang.last_x_days', ['days' => 90])],
            ['id' => 'last_365_days', 'name' => __('laravel-crm::lang.last_x_days', ['days' => 365])],
            ['id' => 'this_month', 'name' => ucfirst(__('laravel-crm::lang.this_month'))],
            ['id' => 'last_month', 'name' => ucfirst(__('laravel-crm::lang.last_month'))],
            ['id' => 'this_quarter', 'name' => ucfirst(__('laravel-crm::lang.this_quarter'))],
            ['id' => 'last_quarter', 'name' => ucfirst(__('laravel-crm::lang.last_quarter'))],
            ['id' => 'this_year', 'name' => ucfirst(__('laravel-crm::lang.this_year'))],
            ['id' => 'last_year', 'name' => ucfirst(__('laravel-crm::lang.last_year'))],
            ['id' => 'all_time', 'name' => ucfirst(__('laravel-crm::lang.all_time'))],
        ];
    }

    protected function chartRange(): array
    {
        $now = CrmDateTime::now();

        return match ($this->chartPeriod) {
            'today' => [CrmDateTime::today()->startOfDay(), $now, 'hour', 'H:i P'],
            'yesterday' => [CrmDateTime::today()->subDay()->startOfDay(), CrmDateTime::today()->subDay()->endOfDay(), 'hour', 'H:i P'],
            'last_7_days' => [CrmDateTime::today()->subDays(6)->startOfDay(), $now, 'day', 'M j'],
            'last_30_days' => [CrmDateTime::today()->subDays(29)->startOfDay(), $now, 'day', 'M j'],
            'last_90_days' => [CrmDateTime::today()->subDays(89)->startOfDay(), $now, 'day', 'M j'],
            'last_365_days' => [CrmDateTime::today()->subDays(364)->startOfDay(), $now, 'week', 'M j'],
            'last_month' => [CrmDateTime::today()->subMonthNoOverflow()->startOfMonth()->startOfDay(), CrmDateTime::today()->subMonthNoOverflow()->endOfMonth()->endOfDay(), 'day', 'M j'],
            'this_quarter' => [CrmDateTime::today()->startOfQuarter()->startOfDay(), $now, 'week', 'M j'],
            'last_quarter' => [CrmDateTime::today()->subQuarter()->startOfQuarter()->startOfDay(), CrmDateTime::today()->subQuarter()->endOfQuarter()->endOfDay(), 'week', 'M j'],
            'this_year' => [CrmDateTime::today()->startOfYear()->startOfDay(), $now, 'month', 'M Y'],
            'last_year' => [CrmDateTime::today()->subYear()->startOfYear()->startOfDay(), CrmDateTime::today()->subYear()->endOfYear()->endOfDay(), 'month', 'M Y'],
            'all_time' => [$this->allTimeStart($now), $now, 'month', 'M Y'],
            default => [CrmDateTime::today()->startOfMonth()->startOfDay(), $now, 'day', 'M j'],
        };
    }

    private function allTimeStart(CarbonInterface $now): CarbonInterface
    {
        $earliestVote = FeatureVote::where('feature_id', $this->feature->id)->min('created_at');
        $earliestView = FeatureView::where('feature_id', $this->feature->id)->min('viewed_at');

        $candidates = array_filter([$earliestVote, $earliestView]);

        if ($candidates === []) {
            return $now->copy()->subMonthNoOverflow()->startOfMonth();
        }

        $earliest = collect($candidates)->map(fn ($v) => CrmDateTime::local($v))->min();

        return ($earliest ?? $now->copy()->subMonthNoOverflow())->startOfMonth();
    }

    protected function buildVotesChart(): array
    {
        return $this->buildChart(
            FeatureVote::where('feature_id', $this->feature->id)->pluck('created_at')->all(),
            ucfirst(__('laravel-crm::lang.votes')),
            ucfirst(__('laravel-crm::lang.cumulative_total')),
            '#05b3a9',
            '#6505B3',
        );
    }

    protected function buildViewsChart(): array
    {
        return $this->buildChart(
            FeatureView::where('feature_id', $this->feature->id)->pluck('viewed_at')->all(),
            ucfirst(__('laravel-crm::lang.views')),
            ucfirst(__('laravel-crm::lang.cumulative_total')),
            '#B34105',
            '#6505B3',
        );
    }

    private function buildChart(array $timestamps, string $barLabel, string $lineLabel, string $barColor, string $lineColor): array
    {
        [$start, $end, $bucket, $format] = $this->chartRange();

        $buckets = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $buckets[$cursor->copy()->getTimestamp()] = ['label' => $cursor->format($format), 'count' => 0];
            $cursor = $this->advanceCursor($cursor, $bucket);
        }

        foreach ($timestamps as $ts) {
            if ($ts === null) {
                continue;
            }

            $when = CrmDateTime::local($ts);

            if ($when->lt($start) || $when->gt($end)) {
                continue;
            }

            $key = $this->bucketKey($when, $start, $bucket);

            if ($key !== null && isset($buckets[$key])) {
                $buckets[$key]['count']++;
            }
        }

        $labels = [];
        $data = [];
        $cumulative = [];
        $running = 0;

        foreach ($buckets as $b) {
            $labels[] = $b['label'];
            $data[] = $b['count'];
            $running += $b['count'];
            $cumulative[] = $running;
        }

        $datasets = [
            [
                'label' => $barLabel,
                'data' => $data,
                'backgroundColor' => $barColor,
                'borderColor' => $barColor,
            ],
        ];

        if ($labels !== []) {
            $datasets[] = [
                'type' => 'line',
                'label' => $lineLabel,
                'data' => $cumulative,
                'borderColor' => $lineColor,
                'backgroundColor' => 'transparent',
                'borderWidth' => 2,
                'pointRadius' => 0,
                'fill' => false,
            ];
        }

        return [
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => $datasets,
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => ['position' => 'bottom'],
                ],
                'scales' => [
                    'y' => ['beginAtZero' => true],
                ],
            ],
        ];
    }

    private function advanceCursor(CarbonInterface $cursor, string $bucket): CarbonInterface
    {
        return match ($bucket) {
            'hour' => $cursor->copy()->setTimestamp($cursor->getTimestamp() + 3600),
            'day' => $cursor->copy()->addDay(),
            'week' => $cursor->copy()->addWeek(),
            'month' => $cursor->copy()->addMonth(),
            default => $cursor->copy()->addDay(),
        };
    }

    private function bucketKey(CarbonInterface $when, CarbonInterface $start, string $bucket): ?int
    {
        return CrmDateTime::bucketKey($when, $start, $bucket);
    }

    public function delete($id)
    {
        if ($feature = Feature::find($id)) {
            $this->authorize('delete', $feature);

            $feature->delete();

            $this->success(
                ucfirst(trans('laravel-crm::lang.feature_deleted')),
                redirectTo: route('laravel-crm.features.index')
            );
        }
    }

    public function render()
    {
        return view('laravel-crm::livewire.features.feature-show');
    }
}
