# Implementation plan va taxminlar

## Boshlang'ich holat
Repozitoriyda faqat talablar fayli bor edi — mavjud kod va Docker sozlamalari yo'q.
Shuning uchun stek qo'shni `*.urtg.uz` loyihalari (hr2, hujjat, at) standartiga moslab tanlandi:
Laravel 13 + Sanctum + spatie/laravel-permission + maatwebsite/excel + dompdf, PHP 8.3-FPM (alpine), MySQL 8.4, nginx.
Frontend: React 19 + TypeScript + Vite 8 + Mantine 9 + TanStack Query + Leaflet/Geoman.

## Asosiy arxitektura qarorlari
| Mavzu | Qaror | Asos |
|---|---|---|
| Uzunlik birligi | Bazada **metr**, `DECIMAL(12,2)`; UI'da km/m formatlash | float xatolarisiz, yagona qoida |
| Pul | `DECIMAL(18,2)` so'm | aniqlik |
| Geometriya | Uchastkada GeoJSON (`LineString`/`MultiLineString`, WGS84) JSON ustunda + bbox ustunlari (B-tree indeks) + hisoblangan geodezik uzunlik | MySQL spatial index faqat `NOT NULL` ustunda ishlaydi, geometriyasiz uchastkalar esa ruxsat etilgan; `GeometryService` abstraksiyasi keyin `LINESTRING SRID 4326` ga o'tishni bitta joyda o'zgartiradi |
| Liniya / uchastka | `cable_lines` 1—N `cable_segments`; statuslar uchastkada yuritiladi, liniyadagi statuslar uchastkalardan **hisoblanadi** (filtrlash uchun saqlanadi) | bitta haqiqat manbai |
| Auth | Sanctum SPA cookie (sessiya + CSRF), bir domen nginx orqali | tokenni JS'da saqlamaslik |
| RBAC | spatie/permission rollar + granular ruxsatlar, Policies; tashkilot kesimi `organizations.view_all` ruxsati bo'lmasa global scope bilan cheklanadi | IDOR'dan himoya serverda |
| Audit | `audit_logs` — faqat qo'shish; model darajasida update/delete taqiqlangan, MySQL trigger ham bloklaydi | o'zgartirib bo'lmaydigan iz |
| Nomuvofiqliklar | `alerts` jadvali: "tekshiruv talab qiladi" ko'rinishida, ayblovsiz | talab 6-bo'lim |
| O'chirish | Reyestr yozuvlari soft delete; tasdiqlangan/moliyaviy yozuvlar o'chirilmaydi — bekor qilish (void/cancel) sabab bilan | audit izi |
| Rangli metall | `cable_types` + versiyalangan `cable_metal_compositions` (tur → liniya → uchastka, eng aniq tasdiqlangan versiya), `metal_mass_calculations` (hisob-kitob tarixi), demontaj topshirig'ida dalolatnoma massalari; ombor massasi kirimlardan | uch ko'rsatkich bir-biriga yozilmaydi; NULL≠0; o'zgarishlar izi saqlanadi |
| Fayllar | `local` private disk, faqat ruxsat tekshiruvli endpoint orqali yuklab olish, MIME/hajm/sha256 | URL orqali ochilmaydi |

## Jarayon zanjiri
Reyestr (liniya → uchastkalar, xarita geometriyasi) → Inventarizatsiya topshirig'i (komissiya, natijalar, farq sababi+hujjat, tasdiq) →
Demontaj topshirig'i (kelishuvlar, murakkab uchastka mutaxassis ko'rigi, holatlar mashinasi, dalolatnoma) →
Ombor kirimi (topshiruvchi ≠ qabul qiluvchi, deklaratsiya/qabul farqi, tasdiqlangan tuzatish, keyingi harakatlar) → Hisobotlar / Audit.
Rangli metall: kg/m (tasdiqlangan) × uzunlik → nazariy massa ↔ dalolatnoma massasi ↔ ombor massasi (farqlar ogohlantirishga tushadi).

## Demontaj holatlari mashinasi
`draft → pending_review → pending_agreement → approved → in_progress → partially_completed → completed`
qo'shimcha: `suspended` (sabab majburiy), `leave_in_place_pending → left_in_place` (qaror + hujjat), `cancelled`.
`approved` ga o'tish: xavfsizlik va ekspluatatsiya kelishuvlari tasdiqlangan bo'lishi, murakkab uchastkada mutaxassis ko'rigi ham bo'lishi shart.
`completed`: demontaj dalolatnomasi biriktirilgan bo'lishi shart.

## Taxminlar
- Demo ma'lumotlar `DEMO`/`TEST` belgisi bilan, demo geometriya `geometry_source = demo` bo'lib xaritada alohida belgilanadi.
- Ruscha tarjima uchun i18n tuzilma tayyor, hozir faqat o'zbek (lotin) matnlari.
- Hujjat shablonlari (PDF) Blade fayllarda: `backend/resources/views/pdf/*` — kodni o'zgartirmasdan tahrirlanadi.
