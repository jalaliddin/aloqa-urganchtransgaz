# Aloqa kabel reyestri — Urganchtransgaz MCHJ

Magistral gaz quvurlari trassalari bo'ylab yotqizilgan eski aloqa kabel liniyalarining raqamli reyestri,
interaktiv GIS xaritasi, inventarizatsiya, demontaj, ombor va audit tizimi.

| Qatlam | Texnologiya |
|---|---|
| Backend | Laravel 13 (PHP 8.3-FPM), Sanctum (cookie SPA auth), spatie/laravel-permission, maatwebsite/excel, dompdf |
| Ma'lumotlar bazasi | MySQL 8.4 |
| Frontend | React 19 + TypeScript, Vite 8, Mantine 9, TanStack Query, React Router 7 |
| Xarita | Leaflet + OpenStreetMap (provayder sozlanadi), "Sun'iy yo'ldosh" va "Gibrid" asosiy qatlamlari, Geoman (chizish/tahrirlash), markercluster, WMS (GeoServer) qatlamlari |
| Infratuzilma | Docker Compose: `nginx`, `backend`, `queue`, `scheduler`, `mysql` (+ dev'da `frontend`) |

Batafsil arxitektura qarorlari: [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md).

## Tez ishga tushirish (lokal)

```bash
cp .env.example .env
# APP_KEY yaratish va .env ga yozish:
docker compose run --rm --no-deps --entrypoint php backend artisan key:generate --show
#   → chiqqan qiymatni .env dagi APP_KEY= ga qo'ying

docker compose up -d --build
docker compose exec backend php artisan db:seed --force      # rollar + DEMO ma'lumotlar (production'da DEMO yuklanmaydi)
```

Ilova: <http://localhost:8090> (port `.env` dagi `APP_PORT`). Nginx `/api` va `/sanctum` so'rovlarini Laravel'ga,
qolganini React ilovasiga yo'naltiradi.

`RUN_MIGRATIONS=true` bo'lsa, `backend` konteyneri har ishga tushganda migratsiyalarni va rollar/ruxsatlar
sinxronizatsiyasini bajaradi. Qo'lda: `docker compose exec backend php artisan migrate --force`.

### DEMO foydalanuvchilar

Barchasining paroli: `Demo12345` (`DEMO_PASSWORD` bilan o'zgartiriladi). Barcha demo yozuvlar `DEMO` deb belgilangan,
xaritadagi demo trassalar shtrixli chiziq bilan ko'rsatiladi va **haqiqiy kabel trassasi emas**.

| E-pochta | Rol | Ko'rish doirasi |
|---|---|---|
| `admin@demo.aloqa.local` | Super Admin | hammasi |
| `rahbar@demo.aloqa.local` | Markaziy apparat / rahbariyat | barcha MGQB |
| `at@demo.aloqa.local` | AT xizmati | barcha MGQB |
| `mgqb1@demo.aloqa.local`, `mgqb2@demo.aloqa.local` | MGQB mas'uli | o'z MGQBsi |
| `brigadir@demo.aloqa.local` | MGQB mas'uli (ish rahbari) | MGQB-1 |
| `komissiya@demo.aloqa.local` | Texnik komissiya | MGQB-1 |
| `xavfsizlik@demo.aloqa.local` | Ekspluatatsiya / sanoat xavfsizligi | MGQB-1 |
| `ombor@demo.aloqa.local` | Ombor moddiy javobgar shaxsi | MGQB-1 |
| `buxgalter@demo.aloqa.local` | Buxgalteriya | barcha MGQB |
| `auditor@demo.aloqa.local` | Auditor (faqat o'qish) | barcha MGQB |

## Development rejimi

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

- `frontend` — Vite dev server (HMR nginx orqali, <http://localhost:8090>);
- backend kodi bind-mount qilinadi (qayta build kerak emas), dev image test vositalari bilan;
- MySQL faqat `127.0.0.1:33070` da ochiladi.

### Frontendni hostda ishga tushirish (`npm run dev`)

Backend Docker'da ishlab turgan bo'lsa (`docker compose up -d`), frontendni hostda ham ishga tushirish mumkin:

```bash
cd frontend && npm install && npm run dev      # http://localhost:5173 (band bo'lsa 5174)
```

Vite `/api` va `/sanctum` so'rovlarini `http://localhost:8090` ga proxy qiladi (boshqa manzil: `VITE_API_PROXY=...`).
Login ishlashi uchun brauzerdagi manzil `.env` dagi `SANCTUM_STATEFUL_DOMAINS` ro'yxatida bo'lishi kerak
(standart: `localhost:5173`, `localhost:5174`). Ro'yxat o'zgarsa: `docker compose up -d backend queue scheduler`.

## Testlar

```bash
# Backend (MySQL'dagi alohida aloqa_test bazasida; asosiy bazaga tegmaydi — himoya TestCase'da)
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec backend php vendor/bin/phpunit

# Frontend unit/komponent testlari, tip tekshiruvi, lint
cd frontend && npm ci && npm test && npx tsc -b && npm run lint

# E2E (ishlab turgan stekka qarshi; desktop va mobil ko'rinish, DEMO ma'lumotlar kerak)
cd frontend && npx playwright install chromium && E2E_BASE_URL=http://localhost:8090 npx playwright test
# yoki o'rnatilgan Google Chrome bilan:  E2E_CHANNEL=chrome npx playwright test
```

E2E xaritada trassa chizib saqlaydi, so'ng uni API orqali (sabab bilan, auditga yozilib) o'chiradi — testni qayta ishga
tushirish mumkin. DEMO holatni to'liq tiklash: `docker compose exec backend php artisan migrate:fresh --seed --force`
(faqat lokal muhitda!).

`aloqa_test` bazasi MySQL volume birinchi marta yaratilganda (`docker/mysql/init`) avtomatik yaratiladi.

## Production: aloqa.urtg.uz

```
Brauzer ─HTTPS─▶ reverse proxy (TLS, aloqa.urtg.uz) ─HTTP─▶ 127.0.0.1:8078 ─▶ nginx konteyneri ─▶ backend (php-fpm) ─▶ mysql
```

Ilova serverda faqat `127.0.0.1:8078` portida tinglaydi, unga tashqaridan faqat reverse proxy orqali kiriladi.
MySQL tashqariga port ochmaydi. Serverda Docker Engine va Compose v2, `aloqa.urtg.uz` uchun DNS A yozuvi kerak.

### 1. `.env`

```bash
cp .env.example .env && chmod 600 .env
openssl rand -hex 24      # DB_PASSWORD uchun; DB_ROOT_PASSWORD uchun yana bir marta
```

Quyidagi qiymatlarni o'zgartiring, qolganlari `.env.example` dagidek qoladi:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://aloqa.urtg.uz
APP_PORT=8078
APP_BIND=127.0.0.1
RUN_MIGRATIONS=true

DB_DATABASE=aloqa
DB_USERNAME=aloqa_app
DB_PASSWORD=<openssl rand -hex 24 natijasi>
DB_ROOT_PASSWORD=<boshqa openssl rand -hex 24 natijasi>

SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=aloqa.urtg.uz
CORS_ALLOWED_ORIGINS=
LOG_LEVEL=warning
```

`APP_KEY` yaratish:

```bash
docker compose run --rm --no-deps --entrypoint php backend artisan key:generate --show
#   → chiqqan base64:... qiymatini .env dagi APP_KEY= ga qo'ying
```

- Parollar hex bo'lsin: `$`, `'` va `#` belgilari Docker Compose va MySQL init skriptida xato beradi.
- `backend` konteyneri ishga tushmaydi, agar `APP_KEY` bo'sh, `APP_DEBUG=true`, `DB_USERNAME=root` yoki
  `DB_PASSWORD` 16 belgidan qisqa bo'lsa. Sababi `docker compose logs backend` da yoziladi.
- `aloqa_app` foydalanuvchisi va root paroli **faqat `mysql_data` volume birinchi marta yaratilganda** o'rnatiladi
  (`docker/mysql/init/01-app-user.sh`). Foydalanuvchi faqat `aloqa` bazasiga huquq oladi. Volume oldin boshqa
  sozlamalar bilan yaratilgan bo'lsa, shu skriptdagi SQL'ni qo'lda bajaring: volume'ni o'chirish barcha ma'lumotni yo'q qiladi.
- Production'da konfiguratsiya ishga tushishda keshlanadi. `.env` o'zgarsa:
  `docker compose up -d --force-recreate backend queue scheduler`.

### 2. Ishga tushirish

```bash
docker compose up -d --build
docker compose ps                         # backend, mysql, nginx — healthy; queue, scheduler — running
curl -s http://127.0.0.1:8078/healthz     # ok
docker compose exec backend php artisan app:create-admin admin@urtg.uz --name="F.I.Sh."    # parol so'raladi
```

Migratsiyalar va rollar/ruxsatlar har ishga tushishda avtomatik bajariladi (`RUN_MIGRATIONS=true`).
Production'da DEMO ma'lumotlar yuklanmaydi: `db:seed` faqat rollarni yaratadi. Keyin administrator tizimda
tashkilotlar (MGQB), foydalanuvchilar, gaz quvurlari va kabel turlarini kiritadi.

### 3. Reverse proxy: aloqa.urtg.uz → 127.0.0.1:8078

**A. Host'dagi nginx (tavsiya etiladi).** `/etc/nginx/sites-available/aloqa.urtg.uz`:

```nginx
server {
    listen 80;
    server_name aloqa.urtg.uz;

    # Hujjatlar 50 MB gacha (DOCUMENT_MAX_KB=51200); ichki nginx ham 60M qabul qiladi
    client_max_body_size 60M;

    location / {
        proxy_pass http://127.0.0.1:8078;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        # Katta Excel/PDF hisobotlar uchun (ichki fastcgi_read_timeout ham 300)
        proxy_read_timeout 300;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/aloqa.urtg.uz /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d aloqa.urtg.uz     # TLS sertifikati va HTTP → HTTPS yo'naltirish
```

**B. Reverse proxy Docker konteynerida bo'lsa** (masalan, `urtg_proxy`). Konteyner ichida `127.0.0.1` konteynerning
o'zi, shuning uchun ilovani host'ning `docker0` manziliga bog'lang:

```bash
ip -4 addr show docker0                   # odatda 172.17.0.1
```

`.env` da `APP_BIND=172.17.0.1`, proxy konfiguratsiyasida `proxy_pass http://172.17.0.1:8078;`, qolgani A variantidagidek.
Bu manzilga konteynerlar yetadi, lokal tarmoqdan esa port ko'rinmaydi.

Ikkala variantda ham:

- Proxy `X-Forwarded-Proto: https` uzatishi shart. `SESSION_SECURE_COOKIE=true` bo'lgani uchun busiz login ishlamaydi.
- `APP_BIND=0.0.0.0` qilmang. Ichki nginx xususiy tarmoq manzillaridan kelgan `X-Forwarded-For` va `X-Forwarded-Proto`
  sarlavhalariga ishonadi, ochiq portda ular soxtalashtirilishi mumkin.

### 4. Tekshirish

```bash
curl -s https://aloqa.urtg.uz/healthz     # ok
curl -sI https://aloqa.urtg.uz/ | head -1 # 200
```

So'ng <https://aloqa.urtg.uz> da administrator bilan kiring.

| Belgi | Nimani tekshirish kerak |
|---|---|
| Login sahifasida qoladi yoki 419 xato | `.env` da `SANCTUM_STATEFUL_DOMAINS=aloqa.urtg.uz`; proxy `X-Forwarded-Proto https` uzatadimi |
| Hujjat yuklashda 413 | proxy'dagi `client_max_body_size` |
| 502 | `docker compose ps`; `ss -ltn` ro'yxatida 8078 port bormi |
| Katta hisobotda 504 | proxy'dagi `proxy_read_timeout` |
| `backend` qayta-qayta ishga tushadi | `docker compose logs backend`: entrypoint tekshiruvi sababni yozadi |

### 5. Yangilash

```bash
# yangi kodni serverga ko'chirgach, avval zaxira nusxa oling (6-bo'lim)
docker compose up -d --build              # migratsiyalar va rollar avtomatik
docker compose ps
```

Ma'lumotlar `mysql_data` va `backend_storage` volume'larida saqlanadi va yangilashda o'chmaydi.
Production'da `migrate:fresh`, `db:wipe` va `docker compose down -v` buyruqlarini hech qachon ishlatmang.

### 6. Zaxira nusxa

```bash
# Ma'lumotlar bazasi (audit triggerlari bilan)
docker compose exec -T mysql sh -c 'exec mysqldump --single-transaction --routines --triggers -uroot -p"$MYSQL_ROOT_PASSWORD" "$DB_DATABASE"' \
  | gzip > aloqa-db-$(date +%F).sql.gz
# Hujjatlar
docker run --rm -v aloqa_backend_storage:/data:ro -v "$PWD":/backup alpine:3 \
  tar czf /backup/aloqa-storage-$(date +%F).tar.gz -C /data .
```

Tiklash:

```bash
gunzip -c aloqa-db-YYYY-MM-DD.sql.gz | docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$DB_DATABASE"'
docker run --rm -v aloqa_backend_storage:/data -v "$PWD":/backup alpine:3 tar xzf /backup/aloqa-storage-YYYY-MM-DD.tar.gz -C /data
```

Nusxani har kuni cron bilan oling va boshqa serverda saqlang.

### 7. Xarita provayderlari

OpenStreetMap tile serveri va standart sun'iy yo'ldosh qatlami (Esri World Imagery, `MAP_SATELLITE_URL`) yuqori yuklama
uchun mo'ljallanmagan. Production'da litsenziyalangan yoki korporativ tile/ortofoto serverini ulang. Buni
`Sozlamalar → Xarita provayderi` sahifasida yoki `MAP_*` o'zgaruvchilari bilan qilish mumkin. Tasvir URL bo'sh qoldirilsa,
sun'iy yo'ldosh va gibrid qatlamlar o'chadi.

## Asosiy qoidalar (qisqacha)

- **Uzunlik** bazada metrda (`DECIMAL(12,2)`), pul `DECIMAL(18,2)`; hisob-kitob `brick/math` bilan, float ishlatilmaydi.
- **Liniya ↔ uchastka**: liniya bir nechta uchastkadan iborat; holatlar uchastkada, liniyadagi holat hisoblanadi.
- **Geometriya**: GeoJSON `LineString`/`MultiLineString` (WGS84), serverda tekshiriladi (hudud, nuqtalar, lat/lng almashuvi),
  geodezik uzunlik va bbox saqlanadi. Koordinatasiz uchastka xaritada ko'rsatilmaydi — taxminiy koordinata uydirilmaydi.
- **Xarita qatlamlari**: o'ng yuqoridagi tugma orqali "Xarita", "Sun'iy yo'ldosh" yoki "Gibrid" (tasvir + joy nomlari)
  tanlanadi; tanlov brauzerda eslab qolinadi va barcha xaritalarga qo'llanadi. Tasvir ustida trassalar oq hoshiya bilan,
  tahrirlanayotgan geometriya esa sariq rangda chiziladi.
- **"Aloqa tizimida ishlatilmaydi"** — faqat dalil + hujjat bilan, tasdiqlangan inventarizatsiya orqali.
- **Rangli metall (Al, Pb, Cu)**: kg/m qiymati kabel turi bo'yicha (`Reyestr → Kabel turlari va metall tarkibi`), kerak bo'lsa
  liniya yoki uchastka uchun alohida. Har bir qiymat versiya sifatida kiritiladi va **ikkinchi vakolatli shaxs tasdiqlagach**
  amalga kiradi; eski versiya o'chirilmaydi. Manba (pasport, normativ, hujjat, o'lchov), kim va qachon kiritgani saqlanadi.
  Nazariy massa = hisob uzunligi (m) × kg/m, `DECIMAL(12,6)` kg/m va `DECIMAL(16,3)` kg, server tomonida aniq decimal hisob.
  **`NULL` = noma'lum, `0` = tekshirilgan nol**: noma'lum qiymat hech qachon 0 deb olinmaydi, noma'lum qismi bor jami
  "to'liq emas" deb ko'rsatiladi. Nazariy, dalolatnoma bo'yicha va omborga qabul qilingan massa — uchta alohida ko'rsatkich,
  har bir metall bo'yicha farq kg va % da; chegara Sozlamalarda (`METAL_TOLERANCE_PERCENT` standart qiymati). Tarkib tasdiqlanganda
  yoki uzunlik o'zgarganda nazariy massa snapshot'i hisob-kitob tarixiga yoziladi.
- **Demontajga ruxsat** — xavfsizlik va ekspluatatsiya kelishuvi, naryad-dopusk, murakkab uchastkada mutaxassislar ko'rigi;
  yakunlash — demontaj dalolatnomasi; joyida qoldirish — qaror + hujjat. Dastur ruxsatni o'zi bermaydi.
- **Vazifalarni ajratish**: natijani yuborgan uni tasdiqlay olmaydi; demontajni bajargan/rahbarlik qilgan materialni qabul qila olmaydi;
  qabul qiluvchi farq bo'yicha qaror qabul qila olmaydi; tuzatish so'ragan uni tasdiqlay olmaydi.
- **Nomuvofiqliklar** (uzunlik/material farqi, hujjat yetishmasligi, tasdiqsiz holat o'zgarishi, takroriy topshiriq,
  shubhali geometriya) ayblov sifatida emas, "tekshiruv talab qiladi" deb qayd etiladi.
- **Audit** — faqat qo'shiladi: model darajasida va MySQL triggerlari bilan o'zgartirish/o'chirish bloklangan.
- **Hujjatlar** private diskda, faqat ruxsat tekshiruvli endpoint orqali; MIME kontent bo'yicha aniqlanadi, sha256 saqlanadi,
  o'chirilmaydi — sabab bilan bekor qilinadi.

## Loyiha tuzilmasi

```
backend/            Laravel API (app/Services — biznes mantiq, app/Policies — ruxsatlar, app/Reports — hisobotlar)
frontend/           React SPA (src/pages, src/components/map — xarita, src/components/common — dizayn tizimi)
docker/nginx/       nginx konfiguratsiyalari (prod: default.conf, dev: dev.conf)
docker/mysql/init/  MySQL birinchi ishga tushirish skripti
docs/               Implementation plan va qarorlar
```

## Ma'lum cheklovlar

- Interfeys hozircha faqat o'zbek (lotin) tilida; rus tili uchun tuzilma tayyor (`frontend/src/i18n`, `backend/lang`).
- Geometriya JSON + bbox ustunlarida saqlanadi; juda katta hajmlarda MySQL spatial ustunlariga o'tish
  `GeometryService` va xarita so'rovida bajariladi.
- Rangli metall tarkibi bitta versiya ichida to'liq to'plam sifatida olinadi (uchastka qiymati alohida metall bo'yicha turdan
  aralashtirilmaydi) — bu ataylab: noma'lum qiymat yuqori darajadan jimgina meros olinmaydi.
- Haqiqiy kg/m normativlari yuklanmagan: DEMO qiymatlar namunaviy; ishga tushirishda kabel pasportlari asosida kiritilishi kerak.
- PDF shablonlar (bayonnoma, dalolatnoma) Blade fayllarda — `backend/resources/views/pdf`; tahrirlash uchun UI muharriri yo'q.
- Bildirishnomalar tizim ichida (bell); e-pochta/SMS yuborish ulanmagan.
