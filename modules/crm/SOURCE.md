# Embedded Laravel CRM source

The CRM code in this directory is owned and versioned by `back-qaturia` while retaining the
`VentureDrake\\LaravelCrm` PHP namespace for serialized jobs, Livewire components, morph types,
policies, and route compatibility.

- Adapted source: `IvanMamaniPerez/pkg-laravelcrm-base`
- Source commit: `3326c42ec0f0e85e146e8d9e1424a290d133039f`
- Host baseline: `IvanMamaniPerez/back-qaturia` commit `a9ccc65d9a9fa131b9187877734e27f00b211105`
- Historical upstream reference only: `venturedrake/laravel-crm` 2.6.0, commit `4ffc0ccc4592fb5b7b4f754cce193cfb29ea0879`

The backend's published migrations remain authoritative. Package migration stubs were not copied.
`App\\Models\\User`, `App\\Models\\Role`, and `App\\Models\\Permission` are the canonical identity
and authorization models.
