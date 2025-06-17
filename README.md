```markdown
# Laravel 高併發 API 範例

此專案展示了一個使用 Laravel 建構的 RESTful API，專注於高併發庫存管理，結合 **Redis 原子操作**、**異步佇列** 和 **JWT 認證**，提供高效、安全的解決方案。專案採用 **Docker** 容器化，適合快速開發、測試和部署。

## 功能特性

* **RESTful API**：清晰的 API 端點，支援版本控制（`/api/v1`）。
* **JWT 認證**：使用 `tymon/jwt-auth` 實現安全身份驗證，包含註冊、登入、登出、獲取使用者資訊和刷新 Token 功能。
* **Redis 原子操作**：透過 `WATCH`、`MULTI`、`EXEC` 和 `SETNX` 實現高併發庫存扣減，防止超賣。
* **分散式鎖**：使用 Redis `SETNX` 實現商品操作鎖，支援重試（帶有隨機退避 10-200ms，最多 5 次嘗試）。
* **異步資料庫更新**：Redis 庫存扣減成功後，透過 Laravel 佇列（`UpdateItemStock` Job）異步更新 MySQL 實際庫存，提升 API 響應速度。
* **自定義錯誤處理**：定義了 `StockInsufficientException`（庫存不足）和 `RedisOperationException`（Redis 操作失敗，如併發衝突）等自定義異常，提供明確的錯誤訊息和狀態碼。
* **Repository 模式**：解耦資料庫和 Redis 操作邏輯，增強代碼的可維護性和可測試性。
* **軟刪除**：商品模型支援邏輯刪除（`deleted_at` 欄位），已軟刪除的商品無法被購買。
* **庫存保護**：資料庫層面（`unsignedInteger` 類型）和模型層面（`setStockAttribute` Mutator）確保庫存值永不為負數。
* **完整測試套件**：包含 Feature 和 Unit 測試，涵蓋認證、商品購買等核心功能，確保應用程式的穩定性和可靠性。

## 環境配置

此專案基於 Docker 進行開發和部署，請確保您的系統已安裝 Docker 和 Docker Compose。

### 1. 複製專案

```bash
git clone https://github.com/BpsEason/laravel-high-concurrency-api.git
cd your-repo-name
```

### 2. 環境變數設定

複製 `.env.example` 並重新命名為 `.env`：

```bash
cp .env.example .env
```

更新 `.env` 檔案中的變數。以下是關鍵變數的範例配置：

```env
APP_NAME="Laravel High-Concurrency API"
APP_ENV=local
APP_KEY= # 在安裝後生成
APP_DEBUG=true
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=root

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_CLIENT=predis

BROADCAST_DRIVER=log
CACHE_DRIVER=redis
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis # 設定為 redis 以使用佇列
SESSION_DRIVER=file
SESSION_LIFETIME=120

MEMCACHED_HOST=127.0.0.1

MAIL_MAILER=log
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=mt1

MIX_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
MIX_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"

# JWT 認證密鑰
JWT_SECRET= # 在安裝後生成

# 分散式鎖配置
LOCK_MAX_RETRIES=5
LOCK_RETRY_DELAY_MIN=10000 # 微秒 (10ms)
LOCK_RETRY_DELAY_MAX=200000 # 微秒 (200ms)

# 測試用戶密碼
TEST_USER_PASSWORD=password
```

### 3. Docker Compose 啟動服務

`docker-compose.yaml` 檔案定義了以下服務：

* `app`：Laravel 應用程式（PHP-FPM）。
* `nginx`：Nginx Web 伺服器（監聽端口 80），將請求轉發給 `app` 服務。
* `mysql`：MySQL 8.0 資料庫。
* `redis`：Redis 服務，用於緩存、佇列和高併發鎖。

啟動所有服務：

```bash
docker-compose up -d --build
```

這將會建構 Docker 映像並在後台啟動所有容器。

### 4. 安裝 Composer 依賴和生成應用程式密鑰

進入 `app` 容器並執行安裝命令：

```bash
docker-compose exec app composer install
docker-compose exec app php artisan key:generate
```

### 5. 生成 JWT Secret

JWT 認證需要一個密鑰，運行以下命令生成：

```bash
docker-compose exec app php artisan jwt:secret
```

### 6. 運行資料庫遷移和填充數據

```bash
docker-compose exec app php artisan migrate --seed
```

### 7. 啟動佇列 Worker

由於庫存更新是異步進行的，您需要啟動一個佇列 Worker 來處理 `UpdateItemStock` Job。

```bash
docker-compose exec app php artisan queue:work redis --queue=stock_updates
```

您可以選擇使用 Supervisor 來管理這個 Worker 進程，`supervisord.conf` 檔案已提供，它將在 `app` 容器啟動時自動運行 `php-fpm`。如果您需要同時運行多個 Worker 或其他進程，可以修改 `supervisord.conf`。

### 8. 權限設置

若遇到儲存或緩存權限問題，進入 `app` 容器並運行：

```bash
docker-compose exec app chmod -R 775 storage bootstrap/cache
docker-compose exec app chown -R www-data:www-data storage bootstrap/cache
```

## API 端點

API 基本 URL: `http://localhost/api/v1`

| 方法 | 端點                              | 描述                                 | 認證要求     |
| :--- | :-------------------------------- | :----------------------------------- | :----------- |
| `POST` | `/auth/register`                  | 使用者註冊                           | 無           |
| `POST` | `/auth/login`                     | 使用者登入並獲取 JWT Token         | 無           |
| `POST` | `/auth/me`                        | 獲取當前認證使用者資訊               | JWT Token    |
| `POST` | `/auth/logout`                    | 使用者登出 (使 JWT Token 失效)     | JWT Token    |
| `POST` | `/auth/refresh`                   | 刷新過期的 JWT Token               | JWT Token    |
| `GET`  | `/items`                          | 獲取所有商品列表                     | 無           |
| `GET`  | `/items/{item}`                   | 獲取單一商品詳情                     | 無           |
| `POST` | `/items/{item}/purchase`          | 購買指定商品並扣減庫存               | JWT Token    |

## 執行測試

運行應用程式測試：

```bash
docker-compose exec app php artisan test
```

## 常見問題 (FAQ)

### 1. 佇列不運行？

* 確認 `.env` 中 `QUEUE_CONNECTION=redis`。
* 確認您已啟動佇列 Worker：
    ```bash
    docker-compose exec app php artisan queue:work redis --queue=stock_updates
    ```

### 2. JWT 錯誤 (例如 Token 無效或密鑰未設置)？

* 確認 `JWT_SECRET` 已在 `.env` 中設置。如果沒有，請運行：
    ```bash
    docker-compose exec app php artisan jwt:secret
    ```

### 3. 庫存不一致 (Redis 和資料庫之間)？

雖然應用程式設計用於最大程度地減少不一致，但在極端情況下仍可能發生。如果 Redis 和資料庫之間的庫存出現不一致，您可以運行以下 Artisan 命令手動同步：

```bash
docker-compose exec app php artisan app:sync-stock # 同步所有商品
docker-compose exec app php artisan app:sync-stock {itemId} # 同步特定商品，例如：php artisan app:sync-stock 1
```

**請注意：** `app:sync-stock` 是一個自定義 Artisan 命令，您需要手動在 `app/Console/Commands` 目錄下創建並實現它。這個命令將會從 MySQL 資料庫讀取商品的實際庫存，然後更新 Redis 中的庫存值，以確保兩者保持同步。

### 4. 服務無法訪問 (例如 502 Bad Gateway)？

* 檢查 `docker-compose logs nginx` 和 `docker-compose logs app` 以查看日誌錯誤。
* 確認 `nginx/default.conf` 中的 `fastcgi_pass app:9000;` 指向了正確的 PHP-FPM 服務名稱 (`app`) 和端口 (`9000`)。
* 確認 `supervisord.conf` 中的 `php-fpm` 進程是否正確運行。
```
