<?php

namespace App\Filament\Resources\Surgeries;

use App\Filament\Resources\Surgeries\Pages\CreateSurgery;
use App\Filament\Resources\Surgeries\Pages\EditSurgery;
use App\Filament\Resources\Surgeries\Pages\ListSurgeries;
use App\Filament\Resources\Surgeries\Schemas\SurgeryForm;
use App\Filament\Resources\Surgeries\Tables\SurgeriesTable;
use App\Models\Surgery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SurgeryResource extends Resource
{
    protected static ?string $model = Surgery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Surgeries';

    protected static string|\UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return SurgeryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SurgeriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSurgeries::route('/'),
            'create' => CreateSurgery::route('/create'),
            'edit' => EditSurgery::route('/{record}/edit'),
        ];
    }

    // ------------------------------------------------------------------
    // Access policies (per role)
    // ------------------------------------------------------------------
    // Admins: view / edit / delete (audit + oversight).
    // Coordinators: view / create / edit (they run scheduling).
    // Only admins may hard-delete a surgery record; coordinators must cancel
    // instead (which is handled by editing status → 'cancelled').

    public static function canCreate(): bool
    {
        $u = auth()->user();
        return $u && in_array($u->role, ['admin', 'coordinator'], true);
    }

    public static function canEdit($record): bool
    {
        $u = auth()->user();
        return $u && in_array($u->role, ['admin', 'coordinator'], true);
    }

    public static function canDelete($record): bool
    {
        $u = auth()->user();
        return $u && $u->role === 'admin';
    }

    public static function canDeleteAny(): bool
    {
        $u = auth()->user();
        return $u && $u->role === 'admin';
    }
}
