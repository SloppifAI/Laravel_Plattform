<?php

namespace Tests\Feature;

use Filament\Forms\Components\Select;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Filters\SelectFilter;
use Tests\TestCase;

class JavaScriptSelectTest extends TestCase
{
    public function test_selects_use_the_javascript_select_by_default(): void
    {
        $this->assertFalse(Select::make('role')->isNative());
        $this->assertFalse(SelectFilter::make('role')->isNative());
        $this->assertFalse(SelectColumn::make('role')->isNative());
    }

    public function test_a_single_select_can_still_opt_back_into_the_native_one(): void
    {
        $this->assertTrue(Select::make('role')->native()->isNative());
    }
}
