<?php

namespace VentureDrake\LaravelCrm\Livewire\Files;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Mary\Traits\Toast;
use VentureDrake\LaravelCrm\Models\File;

class FileItem extends Component
{
    use AuthorizesRequests, Toast;

    public File $file;

    public bool $related = false;

    public function mount(File $file, bool $related = false): void
    {
        $this->file = $file;
        $this->related = $related;
        $this->hydrate();
    }

    public function hydrate(): void
    {
        // A checksummed Livewire model can outlive the authenticated session.
        // Resolve it again through the current business scope before using it.
        $this->file = $this->authorizedFile();
    }

    public function download(): mixed
    {
        $file = $this->authorizedFile();

        return Storage::disk($file->disk)->download($file->file, $file->name);
    }

    private function authorizedFile(): File
    {
        $file = File::query()->findOrFail($this->file->getKey());
        $this->authorize('view', $file);

        return $file;
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->file);

        $this->file->delete();

        $this->success(ucfirst(trans('laravel-crm::lang.file_deleted')));

        $this->dispatch('file-updated');
        $this->dispatch('activity-logged');
    }

    public function render()
    {
        return view('laravel-crm::livewire.files.file-item');
    }
}
