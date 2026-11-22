<?php

namespace App\Enums;

enum BookingTypeIconEnum: string
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
    case LOUNGE_BOOKING = '12';
    case TRANSPORTATION_BOOKING = '13';
    case KITBAG_BOOKING = '14';
    case SHIFTS_BOOKING = '15';
    case DIGITAL_TICKET_BOOKING = '16';
    case DISCUSSION_ROOM_SCHEDULE_BOOKING = '17';
    case WEAPON = '18';
    case WEAPON_TEST_BOOKING = '19';
    case WEAPON_FORM_BOOKING = '20';
    case CONSTRUCTION_BOOKING = '21';
    case ENTRY_PERMISSION_BOOKING = '22';


    // Work spaces
    case WORK_SPACES = '23';
    case LARGE_COMMAND_ROOM_BOOKING = '24'; // חד"ן רחט"ל גדול
    case SMALL_COMMAND_ROOM_BOOKING = '25'; // חד"ן רחט"ל קטן
    case LECTURE_ROOM_BOOKING = '26'; // חדר הרצאות
    case WE_WORK_BOOKING = '27'; // we-work
    case TECH_HAMAMA_FIRST_BOOKING = '28'; // חממה טכנולוגית 1
    case TECH_HAMAMA_SECOND_BOOKING = '29'; // חממה טכנולוגית 2

    // ביטחון מידע (Infosec)
    case INFOSEC_GROUP = '30';
    case CLEARANCE_GRANT_BOOKING = '31';
    case REMOVABLE_MEDIA_BOOKING = '32';
    case SECURITY_INCIDENT_BOOKING = '33';

    // תקשוב (ICT): installations, faults, user management (groups, then types)
    case ICT_INSTALL_GROUP = '38';
    case ICT_FAULT_GROUP = '39';
    case ICT_USERS_GROUP = '40';
    case SOFTWARE_INSTALL_BOOKING = '41';
    case PRINTER_INSTALL_BOOKING = '42';
    case EMAIL_FAULT_BOOKING = '43';
    case USER_RENAME_BOOKING = '44';
    case USER_OPEN_BOOKING = '45';
    case USER_MOVE_BOOKING = '46';
}
