<?php

namespace Database\Seeders\ProdSeeders;

use Illuminate\Database\Seeder;
use App\Models\FieldType;

class FieldTypeSeeder extends Seeder
{
    public function run(): void
    {
        //
        $fieldTypes = [
            ['name' => 'number', 'display_name' => 'מספר'],
            ['name' => 'text', 'display_name' => 'טקסט סגור'],
            ['name' => 'date', 'display_name' => 'תאריך'],
            ['name' => 'time', 'display_name' => 'שעה'],
            ['name' => 'select', 'display_name' => 'רשימת בחירה'],
            ['name' => 'text_box', 'display_name' => 'תיבת טקסט'],
            ['name' => 'text_area', 'display_name' => 'אזור טקסט'],
            ['name' => 'radio', 'display_name' => 'כפתור בחירה'],
            ['name' => 'email', 'display_name' => 'אימייל'],
            ['name' => 'tel', 'display_name' => 'טלפון'],
            ['name' => 'url', 'display_name' => 'כתובת אתר'],
            ['name' => 'password', 'display_name' => 'סיסמה'],
            ['name' => 'file', 'display_name' => 'קובץ'],
            ['name' => 'checkbox', 'display_name' => 'תיבת סימון'],
            ['name' => 'hidden', 'display_name' => 'שדה מוסתר'],
            ['name' => 'color', 'display_name' => 'בחירת צבע'],
            ['name' => 'range', 'display_name' => 'בחירת טווח'],
            ['name' => 'month', 'display_name' => 'שדה חודש'],
            ['name' => 'week', 'display_name' => 'שדה שבוע'],
            ['name' => 'multi_select', 'display_name' => 'בחירה מרובה'],
            ['name' => 'multi_date', 'display_name' => 'תאריכים'],
            ['name' => 'number_with_asterisk', 'display_name' => 'מספר עם כוכבית'],
            ['name' => 'image', 'display_name' => 'תמונה'],
        ];

        foreach ($fieldTypes as $type) {
            FieldType::updateOrCreate(
                ['name' => $type['name']], 
                ['display_name' => $type['display_name']]
            );
        }

    }
}
