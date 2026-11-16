<div class="min-h-screen flex items-center justify-center p-6">
    <x-mary-card title="Create your business" class="w-full max-w-lg shadow-lg">
        <x-mary-form wire:submit="register" novalidate>
            <x-mary-input label="Your name" wire:model="name" required />
            <x-mary-input label="Email" type="email" wire:model="email" required />
            <x-mary-input label="{{ ucfirst(__('laravel-crm::lang.business_name')) }}" wire:model="business_name" required />
            <x-mary-input label="{{ ucfirst(__('laravel-crm::lang.legal_name')) }}" wire:model="legal_name" />
            <x-mary-input label="{{ ucfirst(__('laravel-crm::lang.tax_id')) }}" wire:model="tax_id" />
            <x-mary-input label="Password" type="password" wire:model="password" required />
            <x-mary-input label="Confirm password" type="password" wire:model="password_confirmation" required />
            <x-mary-button label="Create account" type="submit" class="btn-primary w-full" spinner="register" />
            <a href="{{ route('laravel-crm.login') }}" class="link">Sign in</a>
        </x-mary-form>
    </x-mary-card>
</div>
