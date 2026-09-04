<?php

namespace App\Filament\Resources\SurgeryTypes;

use App\Filament\Resources\SurgeryTypes\Pages\CreateSurgeryType;
use App\Filament\Resources\SurgeryTypes\Pages\EditSurgeryType;
use App\Filament\Resources\SurgeryTypes\Pages\ListSurgeryTypes;
use App\Filament\Resources\SurgeryTypes\Schemas\SurgeryTypeForm;
use App\Filament\Resources\SurgeryTypes\Tables\SurgeryTypesTable;
use App\Models\SurgeryType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SurgeryTypeResource extends Resource
{
    protected static ?string $model = SurgeryType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Surgery Types';

    protected static string|\UnitEnum|null $navigationGroup = 'Facilities';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SurgeryTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SurgeryTypesTable::configure($table);
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
            'index' => ListSurgeryTypes::route('/'),
            'create' => CreateSurgeryType::route('/create'),
            'edit' => EditSurgeryType::route('/{record}/edit'),
        ];
    }
}
