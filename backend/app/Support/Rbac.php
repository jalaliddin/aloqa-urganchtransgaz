<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for roles and permissions (spec section 5).
 * Synced to the database by RolesAndPermissionsSeeder.
 */
final class Rbac
{
    public const SUPER_ADMIN = 'super_admin';

    /** @var array<string, string> permission => description (uz) */
    public const PERMISSIONS = [
        'dashboard.view' => "Dashboardni ko'rish",
        'organizations.view_all' => "Barcha tashkilotlar ma'lumotlarini ko'rish",

        'cable_lines.view' => "Kabel reyestrini ko'rish",
        'cable_lines.create' => "Kabel liniyasi qo'shish",
        'cable_lines.update' => 'Kabel liniyasi va uchastkalarini tahrirlash',
        'cable_lines.delete' => "Kabel liniyasini o'chirish (tarixsiz yozuvlar)",
        'cable_lines.view_financial' => "Moliyaviy maydonlarni ko'rish",
        'cable_lines.update_financial' => 'Moliyaviy maydonlarni tahrirlash',
        'cable_lines.edit_geometry' => 'Xaritada geometriyani chizish/tahrirlash, import',
        'metal_composition.manage' => 'Kabel turlari va rangli metall tarkibi (kg/m) qiymatlarini kiritish',
        'metal_composition.approve' => 'Rangli metall tarkibi qiymatlarini tasdiqlash',

        'inspections.view' => "Inventarizatsiyani ko'rish",
        'inspections.manage' => "Inventarizatsiya topshirig'ini yaratish va natijalarni kiritish",
        'inspections.approve' => 'Inventarizatsiya natijasini tasdiqlash/qaytarish',

        'dismantling.view' => "Demontaj topshiriqlarini ko'rish",
        'dismantling.manage' => "Demontaj topshirig'ini yuritish (yaratish, holat, bajarilgan ish)",
        'dismantling.authorize' => 'Demontajga ruxsat berish va joyida qoldirish qarorini qayd etish',
        'approvals.record_safety' => 'Sanoat xavfsizligi/ekspluatatsiya kelishuvlarini qayd etish',
        'approvals.record_specialist' => "Murakkab uchastka mutaxassis ko'rigini qayd etish",

        'materials.view' => "Materiallar va omborni ko'rish",
        'materials.receive' => 'Materialni omborga qabul qilish',
        'materials.approve_discrepancy' => 'Material farqini tasdiqlash/rad etish',
        'warehouse.move' => 'Ombor harakati (topshirish, realizatsiya, utilizatsiya, tuzatish so\'rovi)',
        'warehouse.approve_adjustment' => 'Ombor tuzatishini tasdiqlash',

        'documents.view' => "Hujjatlarni ko'rish va yuklab olish",
        'documents.upload' => 'Hujjat yuklash',
        'documents.void' => 'Hujjatni bekor qilish',

        'reports.view' => "Hisobotlarni ko'rish",
        'reports.export' => 'Hisobotlarni eksport qilish',

        'alerts.view' => "Nomuvofiqliklarni ko'rish",
        'alerts.resolve' => "Nomuvofiqlikni ko'rib chiqish va yopish",
        'audit.view' => "Audit jurnalini ko'rish",

        'users.manage' => 'Foydalanuvchilarni boshqarish',
        'settings.manage' => 'Tizim sozlamalari va spravochniklar',
    ];

    /** @var array<string, array{label: string, permissions: list<string>|string}> */
    public const ROLES = [
        self::SUPER_ADMIN => [
            'label' => 'Super Admin',
            'permissions' => '*',
        ],
        'management' => [
            'label' => 'Markaziy apparat / rahbariyat',
            'permissions' => [
                'dashboard.view', 'organizations.view_all',
                'cable_lines.view', 'cable_lines.view_financial',
                'inspections.view', 'inspections.approve',
                'metal_composition.approve',
                'dismantling.view', 'dismantling.authorize',
                'materials.view', 'materials.approve_discrepancy', 'warehouse.approve_adjustment',
                'documents.view', 'documents.upload',
                'reports.view', 'reports.export',
                'alerts.view', 'alerts.resolve', 'audit.view',
            ],
        ],
        'at_service' => [
            'label' => 'AT xizmati',
            'permissions' => [
                'dashboard.view', 'organizations.view_all',
                'cable_lines.view', 'cable_lines.create', 'cable_lines.update', 'cable_lines.edit_geometry',
                'metal_composition.manage',
                'inspections.view', 'inspections.manage',
                'dismantling.view',
                'materials.view',
                'documents.view', 'documents.upload',
                'reports.view', 'reports.export',
                'alerts.view',
            ],
        ],
        'mgqb_manager' => [
            'label' => "MGQB mas'uli",
            'permissions' => [
                'dashboard.view',
                'cable_lines.view', 'cable_lines.create', 'cable_lines.update', 'cable_lines.edit_geometry',
                'metal_composition.manage',
                'inspections.view', 'inspections.manage',
                'dismantling.view', 'dismantling.manage',
                'materials.view',
                'documents.view', 'documents.upload',
                'reports.view', 'reports.export',
                'alerts.view',
            ],
        ],
        'tech_commission' => [
            'label' => 'Texnik komissiya',
            'permissions' => [
                'dashboard.view',
                'cable_lines.view',
                'inspections.view', 'inspections.manage', 'inspections.approve',
                'metal_composition.approve',
                'dismantling.view', 'approvals.record_specialist',
                'documents.view', 'documents.upload',
                'reports.view',
                'alerts.view',
            ],
        ],
        'safety' => [
            'label' => 'Ekspluatatsiya / sanoat xavfsizligi',
            'permissions' => [
                'dashboard.view',
                'cable_lines.view',
                'inspections.view',
                'dismantling.view', 'approvals.record_safety',
                'documents.view', 'documents.upload',
                'alerts.view',
            ],
        ],
        'warehouse_keeper' => [
            'label' => 'Ombor moddiy javobgar shaxsi',
            'permissions' => [
                'dashboard.view',
                'cable_lines.view',
                'dismantling.view',
                'materials.view', 'materials.receive', 'warehouse.move',
                'documents.view', 'documents.upload',
            ],
        ],
        'accountant' => [
            'label' => 'Buxgalteriya',
            'permissions' => [
                'dashboard.view', 'organizations.view_all',
                'cable_lines.view', 'cable_lines.view_financial', 'cable_lines.update_financial',
                'metal_composition.manage', 'metal_composition.approve',
                'dismantling.view',
                'materials.view', 'materials.approve_discrepancy', 'warehouse.approve_adjustment',
                'documents.view', 'documents.upload',
                'reports.view', 'reports.export',
                'alerts.view',
            ],
        ],
        'auditor' => [
            'label' => "Auditor (faqat o'qish)",
            'permissions' => [
                'dashboard.view', 'organizations.view_all',
                'cable_lines.view', 'cable_lines.view_financial',
                'inspections.view', 'dismantling.view', 'materials.view',
                'documents.view',
                'reports.view', 'reports.export',
                'alerts.view', 'audit.view',
            ],
        ],
    ];

    /** @return list<string> */
    public static function permissionsFor(string $role): array
    {
        $permissions = self::ROLES[$role]['permissions'] ?? [];

        return $permissions === '*' ? array_keys(self::PERMISSIONS) : $permissions;
    }

    public static function roleLabel(string $role): string
    {
        return self::ROLES[$role]['label'] ?? $role;
    }
}
