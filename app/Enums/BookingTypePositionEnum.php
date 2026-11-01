<?php

namespace App\Enums;

enum BookingTypePositionEnum:string
{
    case EQUIPMENT_BOOKING = '2';
    case EQUIPMENT_BOOKING_MALE = '3';
    case EQUIPMENT_BOOKING_FEMALE = '4';
    case CAR = '5';
    case CAR_BOOKING = '6';
    case CAR_MAINTENANCE_BOOKING = '7';
    case FOOD_BOOKING = '8'; 
    case OUT_UNIT_FOOD_BOOKING = '9'; 
    case IN_UNIT_FOOD_BOOKING = '10'; 
    case BARBER_BOOKING = '11';
    // Work spaces
    case WORK_SPACES = '12';
    case LARGE_COMMAND_ROOM_BOOKING = '13';
    case SMALL_COMMAND_ROOM_BOOKING = '14';
    case LECTURE_ROOM_BOOKING = '15';
    case WE_WORK_BOOKING = '16';
    case TECH_HAMAMA_FIRST_BOOKING = '17';
    case TECH_HAMAMA_SECOND_BOOKING = '18';
    case LOUNGE_BOOKING = '19';
    case TRANSPORTATION_BOOKING = '20';
    case CONSTRUCTION_BOOKING = '21';
    case KITBAG_BOOKING = '22';
    case SHIFTS_BOOKING = '23';
    case DIGITAL_TICKET_BOOKING = '24';
    case DISCUSSION_ROOM_SCHEDULE_BOOKING = '25';
    case WEAPON = '26';
    case WEAPON_TEST_BOOKING = '27';
    case WEAPON_FORM_BOOKING = '28';
    case ENTRY_PERMISSION_BOOKING = '29';


    

}
