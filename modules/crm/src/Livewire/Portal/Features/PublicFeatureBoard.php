<?php

namespace VentureDrake\LaravelCrm\Livewire\Portal\Features;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use VentureDrake\LaravelCrm\Models\Feature;
use VentureDrake\LaravelCrm\Models\FeatureStatus;
use VentureDrake\LaravelCrm\Support\BusinessContext;
use VentureDrake\LaravelCrm\Support\PortalTeam;

class PublicFeatureBoard extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public ?string $feature_status_id = null;

    #[Url(as: 'sort')]
    public string $sort = 'votes';

    public function updatingFeatureStatusId(): void
    {
        $this->resetPage();
    }

    public function updatingSort(): void
    {
        $this->resetPage();
    }

    public function statuses(): Collection
    {
        return app(BusinessContext::class)->run($this->portalTeamId(), fn () => FeatureStatus::query()
            ->orderBy('order')
            ->orderBy('id')
            ->get());
    }

    public function features(): LengthAwarePaginator
    {
        return app(BusinessContext::class)->run($this->portalTeamId(), fn () => $this->boardFeatures());
    }

    private function boardFeatures(): LengthAwarePaginator
    {
        $userId = auth()->id();

        $query = Feature::query()
            ->public()
            ->with('status')
            ->when($this->feature_status_id, fn (Builder $q) => $q->where('feature_status_id', $this->feature_status_id));

        if ($userId) {
            $query->withCount(['voters as voted_by_user' => fn ($q) => $q->where(
                config('laravel-crm.db_table_prefix').'feature_votes.user_id',
                $userId
            )]);
        }

        if ($this->sort === 'newest') {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc('votes_count')->orderByDesc('created_at');
        }

        return $query->paginate(10);
    }

    /**
     * The board this component was mounted on, carried across Livewire
     * updates so a second tab on a different board cannot move it.
     */
    public ?string $portalTeamId = null;

    /**
     * The board being shown.
     *
     * `$portalTeamId` is a public property and so is whatever the client sends
     * back — which is harmless, because every public board is public and
     * reachable at its own URL anyway. Falling back to resolve() rather than
     * to null keeps a component mounted without one on the visitor's board.
     */
    protected function portalTeamId(): ?string
    {
        return $this->portalTeamId ?? PortalTeam::resolve();
    }

    public function render()
    {
        return view('laravel-crm::livewire.portal.features.public-feature-board', [
            'features' => $this->features(),
            'statuses' => $this->statuses(),
        ]);
    }
}
