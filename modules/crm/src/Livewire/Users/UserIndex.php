<?php

namespace VentureDrake\LaravelCrm\Livewire\Users;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrm\Notifications\UserInvitationNotification;
use VentureDrake\LaravelCrm\Support\CrmDateTime;
use VentureDrake\LaravelCrm\Support\TeamMembership;
use VentureDrake\LaravelCrm\Traits\ClearsProperties;
use VentureDrake\LaravelCrm\Traits\ResetsPaginationWhenPropsChanges;

class UserIndex extends Component
{
    use AuthorizesRequests, ClearsProperties, ResetsPaginationWhenPropsChanges, Toast, WithPagination;

    public $layout = 'index';

    #[Url]
    public string $tab = 'users';

    #[Url]
    public string $search = '';

    #[Url]
    public ?array $role_id = [];

    #[Url]
    public ?string $crm_access = null;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    public bool $showFilters = false;

    public $dateFormat;

    public function mount()
    {
        $this->dateFormat = app('laravel-crm.settings')->get('date_format', config('laravel-crm.date_format'));
    }

    public function filterCount(): int
    {
        return (count($this->role_id) > 0 ? 1 : 0)
            + ($this->crm_access !== null && $this->crm_access !== '' ? 1 : 0);
    }

    /**
     * Role options for the filter drawer, computed so a debounced search
     * keystroke reuses them rather than re-querying on every render.
     */
    #[Computed]
    public function roles(): Collection
    {
        return Role::crm()->select('id', 'name')->orderBy('name')->get();
    }

    public function headers()
    {
        return [
            ['key' => 'name', 'label' => ucfirst(__('laravel-crm::lang.name'))],
            ['key' => 'email', 'label' => ucfirst(__('laravel-crm::lang.email'))],
            ['key' => 'email_verified_at', 'label' => ucwords(__('laravel-crm::lang.email_verified')), 'format' => fn ($row, $field) => ($field) ? CrmDateTime::local($field)->format($this->dateFormat) : null],
            ['key' => 'crm_access', 'label' => ucfirst(__('laravel-crm::lang.CRM_Access')), 'format' => fn ($row, $field) => $field ? ucfirst(__('laravel-crm::lang.yes')) : ucfirst(__('laravel-crm::lang.no'))],
            ['key' => 'role', 'label' => ucfirst(__('laravel-crm::lang.role')), 'sortable' => false],
            ['key' => 'created_at', 'label' => ucfirst(__('laravel-crm::lang.created')), 'format' => fn ($row, $field) => $field->diffForHumans()],
            ['key' => 'last_online_at', 'label' => ucwords(__('laravel-crm::lang.last_online')), 'format' => fn ($row, $field) => ($field) ? Carbon::parse($field)->diffForHumans() : ucfirst(__('laravel-crm::lang.never'))],

        ];
    }

    public function invitationHeaders(): array
    {
        return [
            ['key' => 'email', 'label' => ucfirst(__('laravel-crm::lang.email'))],
            ['key' => 'role', 'label' => ucfirst(__('laravel-crm::lang.role')), 'sortable' => false],
            ['key' => 'invited_by', 'label' => ucfirst(__('laravel-crm::lang.invited_by')), 'sortable' => false],
            ['key' => 'sent_at', 'label' => ucfirst(__('laravel-crm::lang.sent_at')), 'sortable' => false],
            ['key' => 'last_sent', 'label' => ucfirst(__('laravel-crm::lang.last_sent')), 'sortable' => false],
            ['key' => 'expires', 'label' => ucfirst(__('laravel-crm::lang.expires')), 'sortable' => false],
        ];
    }

    public function users(): LengthAwarePaginator
    {
        // The role cell reads the user's first role; without this it is a query
        // per row.
        return User::with('roles')
            ->when($this->search, function (Builder $q) {
                $q->where('name', 'like', "%$this->search%");
            })
            ->when($this->crm_access !== null && $this->crm_access !== '', fn (Builder $q) => $q->where('crm_access', (bool) $this->crm_access))
            ->when($this->role_id, fn (Builder $q) => $q->whereHas('roles', fn (Builder $q) => $q->where('crm_role', 1)->whereIn('roles.id', $this->role_id)))
            ->orderBy(...array_values($this->sortBy))
            ->paginate(25);
    }

    public function getInvitationsProperty(): LengthAwarePaginator
    {
        return $this->pendingInvitationsQuery()
            ->latest('last_sent_at')
            ->paginate(25);
    }

    public function resendInvitation(string $id): void
    {
        $this->authorize('create', User::class);

        $invitation = $this->pendingInvitationsQuery()->whereKey($id)->first();

        if (! $invitation) {
            return;
        }

        Notification::route('mail', $invitation->email)
            ->notify(new UserInvitationNotification($invitation));

        $invitation->forceFill(['last_sent_at' => now()])->save();

        $this->success(ucfirst(__('laravel-crm::lang.invitation_resent')));
    }

    public function deleteInvitation(string $id): void
    {
        $this->authorize('create', User::class);

        $invitation = $this->pendingInvitationsQuery()->whereKey($id)->first();

        if (! $invitation) {
            return;
        }

        $invitation->delete();

        $this->success(ucfirst(__('laravel-crm::lang.invitation_deleted')));
    }

    protected function pendingInvitationsQuery(): Builder
    {
        // role and invitedByUser are both rendered per row on the invitations
        // tab.
        return UserInvitation::with(['role', 'invitedByUser'])
            ->whereNull('accepted_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->when(config('laravel-crm.teams'), function (Builder $q) {
                $q->where('team_id', auth()->user()?->currentTeam?->id);
            });
    }

    public function delete($id)
    {
        $user = User::find($id);

        if (! $user) {
            return;
        }

        $this->authorize('delete', $user);

        // Self-deletion is a user error rather than a permission failure -- an Owner
        // legitimately holds 'delete crm users', and letting them remove their own
        // account can orphan the last Owner. Surface it as a toast, not a 403.
        if ((string) $user->getKey() === (string) auth()->id()) {
            $this->error(ucfirst(trans('laravel-crm::lang.user_cannot_delete_self')));

            return;
        }

        abort_unless(TeamMembership::inCurrentTeam($user), 403);

        $user->deleteOrFail();

        $this->success(ucfirst(trans('laravel-crm::lang.user_deleted')));
    }

    public function render()
    {
        return view('laravel-crm::livewire.users.user-index', [
            'roles' => $this->roles,
            'filterCount' => $this->filterCount(),
            'headers' => $this->headers(),
            'users' => $this->users(),
            'invitationHeaders' => $this->invitationHeaders(),
        ]);
    }
}
