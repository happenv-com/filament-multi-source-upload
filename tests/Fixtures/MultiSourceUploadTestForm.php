<?php

declare(strict_types=1);

namespace Happenv\FilamentMultiSourceUpload\Tests\Fixtures;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Happenv\FilamentMultiSourceUpload\MultiSourceFileUpload;
use Livewire\Component;

class MultiSourceUploadTestForm extends Component implements HasForms
{
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $saved = [];

    public bool $multiple = false;

    public bool $urlImport = true;

    public bool $required = false;

    public function mount(bool $multiple = false, bool $urlImport = true, bool $required = false): void
    {
        $this->multiple = $multiple;
        $this->urlImport = $urlImport;
        $this->required = $required;
        $this->form->fill();
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->statePath('data')
            ->components([
                MultiSourceFileUpload::make('logo_path')
                    ->key('logo-upload')
                    ->disk('public')
                    ->directory('logos')
                    ->image()
                    ->urlImport($this->urlImport)
                    ->multiple($this->multiple)
                    ->required($this->required)
                    ->maxSize(1024),
            ]);
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>{{ $this->form }}</div>
            BLADE;
    }
}
