# Sagansa Image Service (`img.sagansa.id`)

Microservice Laravel untuk penyimpanan & penyajian gambar. Gambar diupload via API (dikomversi ke WebP), disimpan ke disk `public`, dan disajikan langsung oleh web server dari `public/storage/`.

## Arsitektur Auth

- **Read (menampilkan gambar)**: publik. URL `https://img.sagansa.id/storage/{path}` langsung dibuka browser.
- **Upload / Delete**: dilindungi **Laravel Sanctum** (`auth:sanctum`).

### Token lintas-service
Service ini tidak menerbitkan token sendiri. Ia membaca tabel `personal_access_tokens` di database **`sagansa_user`** (koneksi `mysql_auth`, lihat `config/database.php`). Karena `services/api-ops` membaca tabel yang sama saat user login, **token yang dikeluarkan api-ops langsung valid di sini** — tidak perlu secret bersama, tidak perlu signed URL.

Setup auth meniru persis pola `api-ops`:
- `app/Models/User.php`: `use HasApiTokens;` + `protected $connection = 'mysql_auth';`
- `config/auth.php`: guard `sanctum`.
- Migration `personal_access_tokens` **tidak ada di service ini** — sudah dikelola oleh `services/migration` di database `sagansa_user`.

## Endpoint

| Method | Path | Auth | Deskripsi |
|--------|------|------|-----------|
| `POST` | `/api/upload` | Sanctum bearer | Upload gambar → WebP. Body: `multipart/form-data` field `image` (+ opsional `directory`). Rate limited 30/min. |
| `DELETE` | `/api/images` | Sanctum bearer | Hapus by relative path. Body JSON: `{"path":"..."}`. |
| `GET` | `/api/user` | Sanctum bearer | Info user token (debug). |
| `GET` | `/up` | — | Health check. |

### Contoh upload

```bash
# Token bearer = token login dari api-ops (atau app lain yang pakai sagansa_user)
curl -X POST https://img.sagansa.id/api/upload \
  -H "Authorization: Bearer <token>" \
  -F "image=@photo.jpg" \
  -F "directory=ops/product"
```

Response:
```json
{
  "success": true,
  "url": "https://img.sagansa.id/storage/ops/product/<uuid>.webp",
  "path": "ops/product/<uuid>.webp"
}
```

```ts
// apps/ops (lihat src/services/api.ts uploadImage)
const path = await api.uploadImage(file, 'ops/product');
// kirim `path` ke backend api-ops untuk disimpan di DB
```

## Environment Variables (`.env`)

| Var | Wajib | Contoh | Keterangan |
|-----|-------|--------|------------|
| `APP_URL` | ya | `https://img.sagansa.id` | Basis URL gambar. |
| `APP_ENV` | ya | `production` | Harus `production` di server. |
| `APP_DEBUG` | ya | `false` | Harus `false` di server. |
| `APP_KEY` | ya | (generate) | `php artisan key:generate`. |
| `DB_*` | ya | — | Koneksi default → `sagansa_img` (cache/session/queue). |
| `DB_AUTH_*` | ya | — | Koneksi `sagansa_user` untuk validasi token Sanctum. |

> `IMAGE_UPLOAD_SECRET` (skema HMAC lama) sudah tidak dipakai sejak migrasi ke Sanctum.

## Penyimpanan File

- Disk `public` (`config/filesystems.php`) root = `public_path('storage')`. File ditulis langsung ke `public/storage/{directory}/{uuid}.webp` — **tanpa symlink** `storage:link` (sesuai kebutuhan Hostinger). Array `links` sengaja dikosongkan.
- Re-encode ke WebP via Intervention Image (GD driver) menormalkan payload & menghapus metadata.

## Migrasi File Lama

Salin file gambar lama dari storage app lain (mis. `apps/admin`) ke service ini, mempertahankan relative path:

```bash
# Dry-run dulu untuk lihat rencana
php artisan img:migrate-legacy --dry /path/admin/storage/app/public/images

# Jalankan
php artisan img:migrate-legacy /path/admin/storage/app/public/images /path/admin/storage/app/public/apks
```

Atau via `rsync` langsung di server (lebih cepat untuk file banyak):
```bash
rsync -av --relative /path/admin/storage/app/public/images/./ /path/img/public/storage/
```

## Deploy (Hostinger)

1. Set DNS `img.sagansa.id` → server.
2. vhost domain mengarah ke `services/img/public`.
3. Pastikan ekstensi PHP: `gd` **+ WebP support** (`php -m | grep gd`; `gd` harus mendukung WebP).
4. Web server hardening:
   - `client_max_body_size` (nginx) / `LimitRequestBody` (Apache) ≥ 12M.
   - `upload_max_filesize` + `post_max_size` PHP ≥ 12M.
   - **Disable eksekusi PHP** di direktori `storage` (file gambar harus di-serve statis).
5. Set `.env` produksi (lihat tabel di atas).
6. `php artisan key:generate` (jika `APP_KEY` kosong).
7. `php artisan migrate` (membuat tabel cache/session/queue di `sagansa_img`). Tabel `personal_access_tokens` **tidak dibuat** di sini — pastikan `sagansa_user` (dari `services/migration`) sudah punya tabel tersebut.
8. `php artisan config:cache && php artisan route:cache`.

## Integrasi dengan App Lain

- **`apps/ops` + `services/api-ops`**: sudah terintegrasi. `apps/ops` POST langsung ke `/api/upload` dengan bearer token (lihat `src/services/api.ts`).
- **`apps/admin`**:
  - Serving: `app/Support/PublicStorageUrl.php` membangun URL dari `IMG_SERVICE_URL` (default `https://img.sagansa.id`). Set `IMG_SERVICE_URL` di `.env`.
  - Upload: (rencana) admin pakai service-account token, detail menyusul.
