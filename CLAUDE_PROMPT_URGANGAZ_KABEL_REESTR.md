# Claude Code Prompt --- Urganchtransgaz kabel liniyalari reyestri va interaktiv GIS xaritasi

## 1. Loyiha maqsadi

`Urganchtransgaz` MCHJ tasarrufidagi magistral gaz quvurlari trassalari
bo'ylab yotqizilgan eski aloqa kabel liniyalarini inventarizatsiya
qilish, raqamli reyestrini yuritish, xaritada ko'rsatish, demontaj
jarayonini bosqichma-bosqich nazorat qilish va barcha material-hujjat
harakatini shaffof qayd etish uchun zamonaviy web-dastur ishlab chiq.

Bu loyiha oddiy CRUD emas. U ko'p tashkilotli, xarita/GIS asosidagi
aktivlar reyestri, demontaj monitoringi va audit tizimi bo'lishi kerak.
Hozirgi loyiha repozitoriysini avval tekshir: mavjud kod, papkalar,
coding standard va Docker konfiguratsiyasi bilan tanish. Ishlayotgan
funksiyalarni buzma; keyin bosqichma-bosqich amalga oshir.

## 2. Texnologik stek

-   **Backend:** Laravel (repozitoriydagi versiyani tekshir; mavjud
    loyiha bo'lmasa, barqaror zamonaviy Laravel versiyasidan foydalan).
-   **Database:** MySQL.
-   **Frontend:** React.js + TypeScript.
-   **UI:** Mantine UI, Mantine hooks va Mantine form/notifications
    komponentlari.
-   **Xarita:** Leaflet + OpenStreetMap tile layer. Xarita provayderi
    URL va attribution sozlamalari orqali almashtiriladigan bo'lsin.
    Kelajakda korporativ GIS/GeoServer ulashga tayyor arxitektura tuz.
-   **API:** Laravel REST API, request validation, API resources,
    pagination, filter/sort, standart xatolik javoblari.
-   **Containerlar:** Docker Compose.
-   **Reverse proxy:** Nginx.
-   **Frontend build:** Vite.
-   **Backend test:** Laravel/PHPUnit yoki repozitoriyda mavjud test
    framework.
-   **Frontend test:** mavjud test infratuzilmasiga mos ravishda.
-   **Kod sifati:** type safety, modular tuzilma, qayta ishlatiladigan
    komponentlar, xavfsiz validatsiya va tushunarli README.

Agar repozitoriyda boshqa mos texnologiya allaqachon ishlatilayotgan
bo'lsa, avval uni tahlil qilib, keraksiz qayta yozishdan qoch.

## 3. Docker va konfiguratsiya

Docker Compose orqali quyidagi servislarni ishga tushir:

1.  `nginx` --- reverse proxy, frontend static build va Laravel API
    routing.
2.  `frontend` --- React/Vite development profili yoki production build
    jarayoni.
3.  `backend` --- Laravel PHP-FPM.
4.  `mysql` --- MySQL ma'lumotlar bazasi.
5.  Zarur bo'lsa, `queue` va `scheduler` uchun alohida Laravel worker
    servislar.

Muhim talablar: - Barcha maxfiy qiymatlar `.env` orqali boshqarilsin. -
Foydalanuvchi bergan boshlang'ich lokal sozlamalar: `DB_USERNAME=root`,
`DB_PASSWORD=root`. Ularni faqat local development uchun qo'llash;
production uchun alohida cheklangan DB foydalanuvchisi va kuchli parol
talab qilinsin. - `.env` Git'ga kiritilmasin; `.env.example`
yaratilishi/yangilanishi kerak. - MySQL ma'lumotlari named volume'da
saqlansin. - Healthcheck, restart policy va servislar orasidagi ichki
tarmoq sozlansin. - Nginx `/api` so'rovlarini Laravel backend'ga,
frontend yo'llarini React ilovasiga yo'naltirsin. - Laravel storage va
cache kataloglari uchun to'g'ri permission sozlansin. - Production'da
debug o'chiq bo'lsin; portlarni zarurat bo'lmasa tashqariga ochma. -
`docker compose up -d --build` orqali ishga tushirish va migratsiya/seed
bajarish bo'yicha aniq README yoz. - Development va production
konfiguratsiyalarini imkon qadar alohida profillar yoki fayllar bilan
ajrat.

## 4. Asosiy modullar

### 4.1. Dashboard

Bosh sahifada Mantine UI asosida zamonaviy dashboard yaratiladi. KPI
kartalari: - Jami kabel liniyalari. - Reyestrdagi jami uzunlik (km). -
Inventarizatsiya qilingan uzunlik. - Demontaj rejalashtirilgan
uzunlik. - Demontaj qilingan uzunlik. - Omborga topshirilgan
materiallar. - Tekshiruv talab qiladigan uchastkalar. - Hujjatlari
to'liq bo'lmagan yozuvlar. - Xavfli/murakkab uchastkalar. - MGQBlar
kesimida bajarilish foizi.

Diagrammalar: statuslar bo'yicha taqsimot, MGQB kesimida uzunlik, oylar
bo'yicha demontaj dinamikasi. Raqamlar faqat bazadagi haqiqiy
ma'lumotlardan hisoblansin; demo ma'lumotlar real ma'lumot sifatida
ko'rsatilmasin.

### 4.2. Kabel liniyalari reyestri

Har bir kabel liniyasi va uning uchastkasi uchun quyidagi maydonlarni
qo'llab-quvvatla: - Unikal ID va avtomatik reyestr raqami. - Kabel
nomi/turi, markasi, tomirlar soni (ma'lum bo'lsa). - Yotqizilgan yil
(1960-yillar yoki noma'lum bo'lishi mumkin). - Balans saqlovchi
tashkilot va mas'ul MGQB. - Magistral gaz quvuri/obyekt nomi. -
**Kabelning 1 metriga to'g'ri keladigan rangli metall tarkibi (kg/m):**
alyuminiy (kg/m), qo'rg'oshin (kg/m), mis (kg/m). Ushbu ko'rsatkichlar
kabel markasi/turi bo'yicha saqlansin; zarur bo'lsa, aniq liniya yoki
uchastka uchun alohida qiymat kiritish mumkin bo'lsin. Qiymatlar manba
hujjati bilan bog'lanishi mumkin bo'lsin. -
Boshlanish va tugash nuqtalari. - Loyiha bo'yicha uzunlik va aniqlangan
uzunlik. - Inventar raqami, boshlang'ich qiymati, jamg'arilgan
amortizatsiya, qoldiq qiymati (moliyaviy maydonlar ruxsat bilan
ko'rilsin). - Texnik holat va aloqa tizimida foydalanish holati. - Kabel
turi: yer osti, kanal, yo'l kesishmasi, bino/uy yaqinidan o'tuvchi,
boshqa. - Taxminiy chuqurlik (ma'lum bo'lsa). - GPS
koordinatalari/geometriyasi. - Hujjatlar: texnik pasport, eski sxema,
dalolatnoma, buyruq, foto va boshqa fayllar. - Xavf darajasi, tekshiruv
holati, izoh. - Demontaj holati: rejalashtirilmagan, ko'rik kutilmoqda,
kelishuv kutilmoqda, demontajga ruxsat berilgan, jarayonda, qisman
bajarilgan, to'liq bajarilgan, joyida qoldirish bo'yicha qaror
kutilmoqda, joyida qoldirilgan, to'xtatilgan. -
Yaratilgan/o'zgartirilgan vaqt va foydalanuvchi audit maydonlari.

**Muhim:** bitta kabel liniyasi bir nechta uchastkadan iborat bo'lishi
mumkin. Liniya va uchastkani alohida entity/table sifatida modellashtir.
Bir liniya uchun ko'p geometriya segmentlarini qo'llab-quvvatla.

### 4.3. Interaktiv online xarita

Asosiy imkoniyatlardan biri --- kabel liniyalarini xaritada chizish,
ko'rish va tahrirlash.

Talablar: - Leaflet xaritasi, OSM tile layer va zarur attribution. -
Kabel trassasini **polyline** sifatida xaritada qo'lda chizish. -
Boshlanish/tugash nuqtalarini marker bilan belgilash. - Liniya bo'ylab
bir nechta segment va oraliq nuqtalarni qo'llab-quvvatlash. -
Marker/polyline geometriyasini tahrirlash, saqlash va bekor qilish. -
GeoJSON import/export; koordinatalar va geometriyani backend'da
tekshirish. - Har bir liniya/uchastkaga xaritada noyob rang yoki
statusga qarab rang berish. - Legend: foydalanish holati, texnik holat,
demontaj statusi, xavf darajasi. - MGQB, gaz quvuri, status, yil,
uzunlik va demontaj holati bo'yicha filtrlar. - Qidiruv: reyestr raqami,
kabel ID, gaz quvuri, MGQB, manzil/uchastka. - Liniyani bosganda yon
panelda qisqacha ma'lumot va "Batafsil", "Tahrirlash", "Hujjatlar",
"Demontaj tarixi" amallari. - Marker klasterlash yoki ko'p obyektlarda
xarita unumdorligini oshiradigan usul. - Xarita geometriyasi va reyestr
ma'lumotlari bir-biriga bog'langan bo'lsin. - Koordinatalar bo'lmasa,
obyektni xaritada "geometriya kiritilmagan" deb ko'rsat; joylashuvni
taxminan uydirma koordinata bilan to'ldirma. - OSM foydalanish shartlari
va attribution'ni hurmat qil; yuqori yuklama uchun tile provayderini
sozlanadigan qil. - Xarita mobil va desktop ekranda moslashuvchan
ishlasin.

Geo ma'lumotlar uchun boshlanishida JSON/GeoJSON saqlash mumkin, ammo
ma'lumotlar bazasidagi yechimni toza abstraksiya orqali tashkil et.
MySQL versiyasi va mavjud GIS imkoniyatlarini tekshir; geometriya
ustunlari qo'llansa, migration va query'lar shu versiyaga mos bo'lsin.

### 4.4. Inventarizatsiya va texnik ko'rik

-   Inventarizatsiya topshirig'i yaratish.
-   MGQB va uchastka bo'yicha tekshiruv tayinlash.
-   Tekshiruv natijasi, sana, komissiya a'zolari va izohlar.
-   Foto/video ilovalar.
-   Hisobdagi uzunlik va joyida aniqlangan uzunlikni alohida saqlash.
-   Farq mavjud bo'lsa, sabab va tasdiqlovchi hujjat talab qilish.
-   "Aloqa tizimida ishlatilmaydi" degan status dalil va mas'ul
    tasdig'isiz avtomatik belgilanmasin.
-   Tekshiruv natijasi keyingi bosqichga o'tishi uchun tegishli rol
    tasdig'i talab qilinsin.

### 4.5. Demontajni rejalashtirish va kuzatish

-   Demontaj topshirig'i, ish reja raqami, buyruq raqami/sanasi.
-   Ish bajaruvchi brigada, ish rahbari, mas'ul MGQB va tegishli
    xavfsizlik/ekspluatatsiya kelishuvlari.
-   Rejalashtirilgan va amalda bajarilgan sanalar.
-   Rejalashtirilgan uzunlik va amalda chiqarilgan uzunlik.
-   Demontaj statusi va bosqichlar tarixi.
-   Murakkab joylar uchun alohida belgi: bino/uy yaqinida yoki ostida,
    yo'l ostida, chuqur, boshqa kommunikatsiya kesishmasi, gaz quvuriga
    yaqin, noma'lum joylashuv.
-   Murakkab/xavfli uchastkalar tegishli mutaxassislar ko'rigi va rasmiy
    kelishuvsiz "ishga ruxsat berildi" holatiga o'tmasin.
-   Ishni to'xtatish sababi, hodisa yoki nomuvofiqlik qaydi.
-   Ish tugagach, demontaj dalolatnomasi va tegishli ilovalar
    biriktirilsin.

**Xavfsizlik chegarasi:** dastur xavfsizlik ruxsatini o'zi bermaydi va
qazish usulini avtomatik belgilamaydi. U faqat vakolatli shaxslar
tomonidan kiritilgan ko'rik, kelishuv va ruxsatlarni qayd etadi.
Magistral gaz quvuri yaqinidagi ishlar amaldagi xavfsizlik talablari va
ekspluatatsiya qiluvchi tashkilot tartiblariga muvofiq bajarilishi
kerak.

### 4.6. Materiallar va ombor nazorati

Demontajdan olingan kabel/materiallarning harakati alohida modulda
yuritilsin: - Material turi: kabel butunligi, kabel bo'lagi, alyuminiy,
qo'rg'oshin, mis, boshqa metall chiqindi va boshqa materiallar. - Miqdor,
o'lchov birligi (m, kg, dona), tortish/o'lchash sanasi. - Qaysi liniya,
uchastka va demontaj topshirig'idan olinganligi. - Topshiruvchi va qabul
qiluvchi shaxs. - Ombor kirim hujjati raqami va sanasi. - Saqlash joyi. -
Keyingi topshirish/realizatsiya/utilizatsiya hujjati. - Foto va
tarozi/o'lchov dalillari. - Hisoblangan, demontaj qilingan va omborga
qabul qilingan miqdorlar orasidagi farq. - Farq bo'lsa, sabab,
tushuntirish va tasdiqlash jarayoni. - Miqdorlarni avtomatik
"tenglashtirib" yuborma; nomuvofiqlikni ko'rsat va tasdiqlangan tuzatish
izini saqla.

**Rangli metall miqdorini hisoblash:** - Har bir kabel turi/markasi yoki
kerak bo'lsa individual uchastka uchun 1 metr kabel tarkibidagi metall
miqdori alohida kiritilsin: alyuminiy (kg/m), qo'rg'oshin (kg/m), mis
(kg/m). - Liniya/uchastkaning hisob-kitob uzunligi metrga o'tkazilib,
har bir metall bo'yicha nazariy massa `uzunlik_m × kg_per_meter` formulasi
bilan hisoblab ko'rsatilsin. - Alyuminiy, qo'rg'oshin va mis uchun
hisoblangan nazariy massa alohida ustunlarda va jami kesimlarda ko'rsatilsin.
- Nazariy massa, dalolatnoma bo'yicha amalda olingan massa va omborga
haqiqatan qabul qilingan massa **uchta alohida ko'rsatkich** bo'lsin; ular
bir-birining ustiga yozilmasin. - Har bir metall bo'yicha farq kg va foizda
hisoblansin; kerak bo'lsa ruxsat etilgan farq chegarasi sozlamadan
boshqarilsin, lekin farq hech qachon yashirilmasin. - `NULL`/bo'sh qiymat
"ma'lumot noma'lum" degani, `0` esa "tekshirilgan va metall miqdori nol"
degani bo'lsin. Noma'lum ko'rsatkichni 0 deb avtomatik to'ldirma va
noma'lum qiymatlarda nazariy massani ishonchli hisoblangan sifatida
ko'rsatma. - Kg/m ko'rsatkichining manbasi (kabel pasporti, marka bo'yicha
normativ ma'lumot yoki tasdiqlovchi hujjat), kiritgan shaxs va kiritilgan
sana qayd etilsin.

**Vazifalarni ajratish:** demontajni bajargan shaxs materialni omborga
qabul qiluvchi yagona shaxs bo'la olmasin. Rol va vakolatlar orqali
topshiruvchi/qabul qiluvchi ajratilsin.

### 4.7. Hujjatlar va dalolatnomalar

-   O'ztransgaz AJ buyrug'i.
-   Urganchtransgaz MCHJ ichki buyrug'i.
-   Komissiya tarkibi va bayonnomasi.
-   Texnik ko'rik/xulosa.
-   Ishga ruxsat va kelishuv hujjatlari.
-   Demontaj dalolatnomasi.
-   Ombor kirim hujjati.
-   Hisobdan chiqarish hujjatlari.
-   Murakkab uchastkani joyida qoldirish bo'yicha qaror va asos.
-   Foto/video ilovalar.

Fayllar xavfsiz saqlansin, fayl turi/hajmi tekshirilsin, faqat vakolatli
foydalanuvchilar ko'rsin. PDF/Excel eksporti uchun backend servislaridan
foydalan. Hujjat shablonlarini keyinchalik tahrirlash mumkin bo'ladigan
qilib tuz.

### 4.8. Hisobotlar

-   MGQB kesimida kabel uzunligi va holati.
-   Kabel liniyalari reyestri.
-   Xatlov natijalari va farqlar.
-   Demontaj reja/fakt.
-   Materiallar: demontaj qilingan va omborga qabul qilingan.
-   Hujjatlari to'liq bo'lmagan uchastkalar.
-   Xavfli/murakkab uchastkalar.
-   Joyida qoldirilgan kabel va qaror asoslari.
-   Sana oralig'i, MGQB, status va boshqa filtrlar.
-   Excel/CSV eksporti; eksportga faqat foydalanuvchi vakolati
    doirasidagi ma'lumotlar kirsin.

## 5. Rollar va ruxsatlar (RBAC)

Laravel Policies/Gates yoki loyiha uchun mos RBAC paketi orqali server
tomonida ruxsatlarni tekshir. Faqat frontend'da tugmani yashirish
xavfsizlik uchun yetarli emas.

Tavsiya etilgan rollar: 1. **Super Admin** --- tizim konfiguratsiyasi,
foydalanuvchilar va barcha tashkilotlar. 2. **Markaziy apparat /
rahbariyat** --- barcha MGQBlar bo'yicha ko'rish, tasdiqlash va
hisobotlar. 3. **AT xizmati** --- kabel/aloqa ma'lumotlari, xarita
geometriyasi, texnik reyestr va tekshiruv natijalari; o'z vakolati bilan
cheklangan. 4. **MGQB mas'uli** --- o'z tashkiloti uchastkalari va ish
ma'lumotlarini yuritish. 5. **Texnik komissiya** --- ko'rik, xulosa,
foto va dalolatnomalarni kiritish/tasdiqlash. 6. **Ekspluatatsiya/sanoat
xavfsizligi** --- tegishli kelishuvlar va xavfsizlik tekshiruvlarini
qayd etish. 7. **Ombor moddiy javobgar shaxsi** --- materiallarni qabul
qilish/kirim qilish; demontaj ma'lumotlarini o'zboshimchalik bilan
o'zgartira olmasin. 8. **Buxgalteriya** --- balans qiymati,
amortizatsiya va hisobdan chiqarish hujjatlari. 9. **Auditor / faqat
o'qish** --- audit va hisobotlarni ko'rish, yozuvlarni o'zgartirmaslik.

Tashkilotlar bo'yicha ma'lumot ajratilishi serverda qat'iy tekshirilsin.
Oddiy foydalanuvchi boshqa MGQB ma'lumotlarini URL yoki API parametrini
o'zgartirish orqali ko'ra olmasin. Kritik tasdiqlar uchun foydalanuvchi,
sana-vaqt va qaror sababi qayd etilsin.

## 6. Audit va kamomadni aniqlash

Har qanday muhim o'zgarish audit jurnaliga yozilsin: - kim; - qachon; -
qaysi obyekt; - eski qiymat/yangi qiymat; - amal turi; - izoh yoki
sabab; - IP/session ma'lumotlari --- qonuniy va xavfsizlik siyosatiga
muvofiq.

Audit log oddiy foydalanuvchi tomonidan o'zgartirilmasin yoki o'chirib
yuborilmasin.

Avtomatik ogohlantirishlar: - hisobdagi va chiqarilgan uzunlik farqi; -
chiqarilgan va omborga qabul qilingan miqdor farqi; - majburiy hujjat
yetishmasligi; - tasdiqsiz status o'zgartirishga urinish; - bir
foydalanuvchining o'zaro ajratilishi kerak bo'lgan amallarni bajarishga
urinishi; - bir uchastkaga takroriy demontaj topshirig'i; - geometriya
yoki uzunlikdagi shubhali o'zgarish.

Tizim "o'g'irlik sodir bo'ldi" deb avtomatik ayblov chiqarmasin; faqat
**nomuvofiqlik yoki tekshiruv talab qiladigan holat** sifatida qayd
etsin.

## 7. Zamonaviy UI/UX talablari

React + Mantine asosida professional enterprise UI yaratilishi kerak: -
Soddalashtirilgan, toza va zamonaviy interfeys. - Responsive layout:
desktop, tablet va mobil. - Chap sidebar navigatsiya, yuqori panel,
breadcrumb va profil menyusi. - Dashboard KPI kartalari va tushunarli
grafiklar. - Katta reyestr jadvallari uchun server-side pagination,
sorting, filtering, column visibility va qidiruv. - Xarita va jadvalni
yonma-yon ko'rsatish yoki "Xarita/Jadval" rejimlari. - Xarita obyektini
tanlaganda reyestrdagi tegishli qator ham tanlansin va aksincha. -
Statuslar uchun izchil ranglar va matnli label; rangning o'zi yagona
ma'no tashuvchisi bo'lmasin. - Formlarda aniq validation, foydali xato
xabarlari, loading/empty/error states. - Saqlashdan oldin tasdiqlash
dialoglari, ayniqsa kritik amallar uchun. - Toast notifications,
skeleton loading va foydalanuvchi uchun tushunarli empty state. -
Accessibility: klaviatura bilan boshqarish, kontrast, label va fokus
holatlari. - Uzbek Latin asosiy til; arxitekturani keyinchalik rus
tiliga tarjima qilishga tayyor qil. - Mantine komponentlarini yagona
design system orqali ishlat: ranglar, spacing, radius, typography va
button variantlari. - Tasodifiy ranglar, ortiqcha gradientlar va haddan
tashqari bezaklardan qoch; professional korporativ ko'rinish yarat.

Asosiy menyu: 1. Dashboard 2. Kabel liniyalari reyestri 3. Interaktiv
xarita 4. Inventarizatsiya 5. Demontaj topshiriqlari 6. Materiallar va
ombor 7. Hujjatlar 8. Hisobotlar 9. Foydalanuvchilar va rollar 10. Audit
jurnali 11. Sozlamalar

## 8. Ma'lumotlar bazasi va biznes qoidalari

Toza normalizatsiyalangan schema ishlab chiq. Boshlang'ich entity'lar: -
organizations - users - roles/permissions - cable_types (kabel markasi/turi
va tavsifi) - cable_metal_compositions (kabel turi/markasi bo'yicha
aluminium_kg_per_meter, lead_kg_per_meter, copper_kg_per_meter,
manba_hujjati va kiritish metama'lumotlari) - cable_lines - cable_segments
(kerak bo'lsa kg/m qiymatlarini kabel turi bo'yicha meros qilib olsin,
lekin uchastka uchun alohida tasdiqlangan qiymat kiritish imkoniyati
bo'lsin) - gas_pipeline_assets - inspections - dismantling_orders -
dismantling_events - material_receipts - material_items -
warehouse_transactions - documents/attachments - approvals - audit_logs -
notifications

Kerakli foreign key, unique index, query index va soft delete siyosatini
asoslab tanla. Moliyaviy yoki tasdiqlangan yozuvlarni shunchaki o'chirib
yuborish o'rniga audit izi bilan bekor qilish/tuzatish oqimini yarat.
Uzunliklar uchun bir xil o'lchov qoidasi ishlat: bazada aniq standart
birlik tanla, UI'da km/m ko'rsatishni to'g'ri konvertatsiya qil.
Floating-point xatolaridan qochish uchun uzunlik, pul qiymatlari va kg/m
ko'rsatkichlariga mos `DECIMAL` turlaridan foydalan. Metall miqdori uchun
kamida 4 ta kasr xonasini saqlash imkoniyatini ko'zda tut; haqiqiy precision
va scale biznes ma'lumotlari bilan tekshirilsin. Nazariy massa, amaldagi
massa va ombor massasi bo'yicha hisob-kitoblar server tomonida ham
tekshirilsin. Kabel uzunligi va tarkib qiymati o'zgarsa, oldingi hisob-kitob
natijalarini audit tarixida saqla yoki hisob-kitob versiyasi bilan bog'la.

## 9. API va xavfsizlik

-   Barcha kirish ma'lumotlari serverda validate qilinsin.
-   Authentication uchun loyihaning mavjud yechimini tekshir; mavjud
    bo'lmasa, React SPA uchun Laravel Sanctum kabi mos usulni tanla.
-   CSRF/CORS/cookie sozlamalarini deployment modeliga mosla.
-   SQL injection, XSS, IDOR va fayl upload xavflaridan himoyalan.
-   Rate limiting va login urinishlari nazorati.
-   Parollar faqat xavfsiz hash ko'rinishida saqlansin.
-   Muhim o'zgarishlar audit qilinsin.
-   Maxfiy kalitlar va DB parollarini kodga yozma.
-   Production'da HTTPS reverse proxy ortida ishlashga tayyorla.
-   Hujjatlar URL orqali ruxsatsiz ochilmasin.
-   Katta fayllar uchun hajm va format cheklovlari bo'lsin.

## 10. Seed va demo ma'lumotlar

Development uchun aniq `Demo` yoki `TEST` deb belgilangan seed
ma'lumotlar yarat: - bir nechta MGQB; - kabel liniyalari va
segmentlar; - xaritada haqiqiy emas, demo ekanligi ko'rsatilgan
namunaviy geometriyalar; - inventarizatsiya; - demontaj topshiriqlari; -
ombor kirimlari; - turli ruxsat darajasidagi test foydalanuvchilar.

Demo koordinatalarni haqiqiy kabel trassasi sifatida taqdim etma. Real
ma'lumotlar import qilinganda koordinatalar tekshirilishi va manbasi
qayd etilishi kerak.

## 11. Testlar va qabul mezonlari

Quyidagilarni tekshiradigan testlar yoz: - autentifikatsiya va
role/organization ruxsatlari; - kabel liniyasi va segment CRUD; -
GeoJSON/geometriya validatsiyasi; - reyestr filtrlari va pagination; -
inventarizatsiya natijasini saqlash; - demontaj statuslari va tasdiqlash
oqimi; - omborga kirim va miqdor farqlarini aniqlash; - kg/m tarkib ma'lumotlarini
validatsiya qilish; - kabel uzunligidan alyuminiy, qo'rg'oshin va misning
nazariy massasini to'g'ri hisoblash; - noma'lum (`NULL`) qiymatni nol deb
hisoblamaslik; - hisoblangan, amalda olingan va omborga qabul qilingan
massa farqlarini alohida ko'rsatish; - audit log; - faylga ruxsatsiz
kirish taqiqlanishi; - hisobot eksporti; - mobil layout
va xarita/jadval o'zaro bog'lanishi.

Qabul mezonlari: 1. Docker orqali loyiha README bo'yicha ishga tushadi.
2. React UI Nginx orqali ochiladi, `/api` Laravel'ga boradi. 3. Login va
rollar ishlaydi. 4. Kabel liniyasini reyestrga qo'shish, tahrirlash,
filtrlash va ko'rish mumkin. 5. Xaritada liniyani chizish/tahrirlash va
bazaga saqlash ishlaydi. 6. Xaritadagi obyekt reyestrdagi obyekt bilan
bir xil yozuvga bog'langan. 7. Inventarizatsiya, demontaj va ombor
jarayonlari o'zaro bog'langan. 8. Miqdorlar farqi yashirilmaydi, auditga
tushadi. 9. Ruxsatsiz foydalanuvchi boshqa tashkilot ma'lumotlariga kira
olmaydi. 10. Kabelning kg/m ma'lumotlaridan alyuminiy, qo'rg'oshin va mis
bo'yicha nazariy massa hisoblanadi; hisob, amalda olingan miqdor va ombor
kirimi farqlari alohida ko'rsatiladi. 11. Noma'lum metall tarkibi
ma'lumotlari nol sifatida qabul qilinmaydi. 12. Muhim biznes jarayonlari
testlar bilan qoplangan.

## 12. Ishni bajarish tartibi

Quyidagi tartibda ishlagin: 1. Repozitoriy, mavjud kod va Docker
sozlamalarini tahlil qil. 2. Qisqa implementation plan va taxminlarni
yoz. 3. Database schema va migratsiyalarni yarat. 4. Laravel modellar,
servislar, policies, API endpointlar va testlarni amalga oshir. 5.
React + Mantine layout, auth, dashboard va reyestr sahifalarini yarat.
6. Leaflet xarita va GeoJSON chizish/tahrirlash funksiyalarini
integratsiya qil. 7. Inventarizatsiya, demontaj, ombor, hujjatlar va
audit oqimini ulab chiq. 8. Docker/Nginx konfiguratsiyasini tekshir. 9.
Testlar, lint/build va migratsiyalarni bajar; aniqlangan xatolarni
tuzat. 10. README, `.env.example`, demo seed va ishga tushirish
yo'riqnomasini yangila. 11. Yakunda yaratilgan funksiyalar, bajarilgan
testlar, ishga tushirish buyruqlari va hali bajarilmagan ishlarni qisqa
hisobot qil.

Har bir bosqichda avval mavjud fayllarni tekshir. Fayllarni keraksiz
to'liq qayta yozma. TODO yoki soxta ishlaydigan tugmalar qoldirma. Agar
funksiyani to'liq amalga oshira olmasang, cheklovni ochiq yoz va ishga
tushadigan eng kichik to'liq yechimni yarat.

**Yakuniy maqsad:** Urganchtransgaz MCHJ uchun zamonaviy, xavfsiz, audit
qilinadigan, xaritada online kabel trassalarini ko'rsatadigan va
reyestr--inventarizatsiya--demontaj--ombor jarayonlarini birlashtirgan
ishlab turgan web-platforma.
