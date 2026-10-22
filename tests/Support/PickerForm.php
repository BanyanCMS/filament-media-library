<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use Banyan\FilamentMediaLibrary\Enums\MediaKind;
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

    /** @var list<string> */
    public array $kinds = ['image'];

    public ?string $folder = null;

    public bool $allowRestricted = false;

    /** @param list<string> $kinds */
    public function mount(bool $uploadable = true, bool $thumbnails = false, array $kinds = ['image'], ?string $folder = null, bool $allowRestricted = false): void
    {
        $this->allowRestricted = $allowRestricted;
        $this->folder = $folder;
        $this->kinds = $kinds;
        $this->uploadable = $uploadable;
        $this->thumbnails = $thumbnails;
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                MediaPicker::make('hero_id')->kinds(array_map(MediaKind::from(...), $this->kinds))->uploadable($this->uploadable)->thumbnails($this->thumbnails)->folder($this->folder)->allowRestricted($this->allowRestricted),
                MediaPicker::make('gallery')->multiple()->uploadable($this->uploadable)->allowRestricted($this->allowRestricted),
            ])
            ->statePath('data');
    }

    public function render(): View
    {
        return view('media-library-tests::picker-form');
    }
}
