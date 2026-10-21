<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use Banyan\FilamentMediaLibrary\Forms\MediaPicker;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Component as LivewireComponent;

/** The smallest host for a MediaPicker: one single and one multiple field, no record. */
final class PickerForm extends LivewireComponent implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public bool $uploadable = true;

    public bool $thumbnails = false;

    public function mount(bool $uploadable = true, bool $thumbnails = false): void
    {
        $this->uploadable = $uploadable;
        $this->thumbnails = $thumbnails;
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                MediaPicker::make('hero_id')->uploadable($this->uploadable)->thumbnails($this->thumbnails),
                MediaPicker::make('gallery')->multiple()->uploadable($this->uploadable),
            ])
            ->statePath('data');
    }

    public function render(): View
    {
        return view('media-library-tests::picker-form');
    }
}
