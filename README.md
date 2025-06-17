# Laravel 高併發 API 範例

此專案展示了一個使用 Laravel 建構的 RESTful API，專注於高併發庫存管理，結合 **Redis 原子操作**、**異步佇列** 和 **JWT 認證**，提供高效、安全的解決方案。專案採用 **Docker** 容器化和 **GitLab CI/CD** 自動化部署，適合快速開發、測試和分享到 GitHub。

## 功能特性

- **RESTful API**：清晰的 API 端點，支援版本控制（`/api/v1`）。
- **JWT 認證**：使用 `tymon/jwt-auth` 實現安全身份驗證。
- **Redis 原子操作**：透過 `WATCH`、`MULTI`、`EXEC` 和 `SETNX` 實現高併發庫存扣減，防止超賣。
- **分散式鎖**：使用 Redis `SETNX` 實現鎖定，支援重試（隨機退避 10-200ms，最多 5 次）。
- **異步資料庫更新**：Redis 扣減後，透過 Laravel 佇列（`UpdateItemStock` Job）異步更新 MySQL，提升響應速度。
- **錯誤處理**：自訂異常（`StockInsufficientException`、`RedisOperationException`）提供明確錯誤訊息和日誌。
- **Repository 模式**：解耦資料庫和 Redis 操作，增強可維護性。
- **軟刪除**：商品支援邏輯刪除（`deleted_at`），已刪除商品不可購買。
- **庫存保護**：資料庫（`unsignedInteger`）和模型（`setStockAttribute`）確保庫存非負。
- **Docker 環境**：使用 `docker-compose` 提供一致的開發和測試環境。
- **CI/CD 自動化**：透過 GitLab CI/CD 實現建置、測試和部署。
- **測試支援**：包含單元測試、功能測試和壓力測試（Locust）。

## 技術棧

- **後端**：Laravel 10.x（PHP 8.2+）
- **資料庫**：MySQL 8.0
- **快取/鎖定/佇列**：Redis
- **認證**：Tymon/JWT-Auth
- **容器化**：Docker, Docker Compose
- **CI/CD**：GitLab CI/CD

## 快速開始

### 前置條件

- 安裝 [Docker](https://www.docker.com/get-started/) 和 [Docker Compose](https://docs.docker.com/compose/install/)
- Git 已配置
- GitLab 帳號（用於 CI/CD）

### 本地設置

1. **複製儲存庫**：
   ```bash
   git clone https://github.com/BpsEason/laravel-high-concurrency-api.git
   cd laravel-high-concurrency-api
   ```

2. **配置環境**：
   - 複製 `.env` 範例：
     ```bash
     cp .env.example .env
     ```
   - 編輯 `.env`，確保包含以下關鍵變數：
     ```env
     APP_NAME=Laravel
     APP_ENV=local
     APP_KEY=base64:your_app_key_here
     APP_DEBUG=true
     APP_URL=http://localhost
     DB_CONNECTION=mysql
     DB_HOST=mysql
     DB_PORT=3306
     DB_DATABASE=laravel
     DB_USERNAME=root
     DB_PASSWORD=root
     REDIS_HOST=redis
     REDIS_PORT=6379
     QUEUE_CONNECTION=redis
     JWT_SECRET=your_jwt_secret_here
     LOCK_MAX_RETRIES=5
     LOCK_RETRY_DELAY_MIN=10000
     LOCK_RETRY_DELAY_MAX=200000
     TEST_USER_PASSWORD=password
     ```
   - 生成 `APP_KEY`：
     ```bash
     docker-compose exec app php artisan key:generate
     ```

3. **啟動 Docker 容器**：
   ```bash
   docker-compose up -d
   ```

4. **安裝依賴並生成 JWT Secret**：
   ```bash
   docker-compose exec app composer install
   docker-compose exec app php artisan jwt:secret
   ```
   將生成的 `JWT_SECRET` 複製到 `.env`。

5. **運行資料庫遷移**：
   ```bash
   docker-compose exec app php artisan migrate
   ```

6. **填充測試數據**：
   ```bash
   docker-compose exec app php artisan db:seed
   ```

7. **啟動佇列 Worker**：
   - 長期運行（新終端或背景執行）：
     ```bash
     docker-compose exec app php artisan queue:work redis --queue=stock_updates
     ```
   - 或使用 Supervisor 管理（生產環境推薦）。

### 注意
- 確保 `users` 表遷移已運行（通常由 Laravel 預設提供）：
  ```bash
  docker-compose exec app php artisan migrate
  ```

### 訪問 API

API 基礎 URL：`http://localhost/api/v1`

## API 端點

### 身份驗證 (`/api/v1/auth`)

- **POST /auth/register**
  - Body: `{ "name": "string", "email": "string", "password": "string" }`
  - 描述：註冊新用戶
- **POST /auth/login**
  - Body: `{ "email": "string", "password": "string" }`
  - 描述：登錄並獲取 JWT Token
- **POST /auth/me**（需 `Authorization: Bearer <token>`）
  - 描述：獲取當前用戶資訊
- **POST /auth/logout**（需 `Authorization: Bearer <token>`）
  - 描述：登出並使 Token 失效
- **POST /auth/refresh**（需 `Authorization: Bearer <token>`）
  - 描述：刷新 Token

### 商品管理 (`/api/v1/items`)

- **GET /items**
  - 描述：列出所有商品
- **GET /items/{id}**
  - 描述：獲取指定商品
- **POST /items/{id}/purchase**（需 `Authorization: Bearer <token>`）
  - Body: `{ "quantity": integer }`
  - 描述：購買商品，使用 Redis 原子操作和異步佇列

## 高併發庫存管理

### 實現邏輯

1. **分散式鎖**（`ItemService.php`）：
   - 使用 Redis `SETNX` 獲取鎖，失敗時隨機退避（10-200ms）重試，最多 5 次。
   - 若無法獲取鎖，拋出 `RedisOperationException`。

2. **Redis 原子操作**：
   - 使用 `WATCH` 監聽庫存鍵，檢查庫存是否足夠。
   - 若庫存為 `null`，從資料庫（排除軟刪除商品）初始化 Redis。
   - 使用 `MULTI`/`DECRBY`/`EXEC` 執行原子扣減。
   - 若事務失敗（併發衝突），拋出 `RedisOperationException`。

3. **異步更新**（`UpdateItemStock.php`）：
   - Redis 扣減成功後，分派 `UpdateItemStock` Job 到 `stock_updates` 佇列。
   - Job 檢查資料庫庫存，執行 `decrement` 更新，記錄原始和新庫存。
   - 支援 3 次重試，每次間隔 5 秒。

4. **錯誤處理**：
   - 庫存不足：拋出 `StockInsufficientException`，返回當前庫存。
   - 商品不存在或已刪除：拋出 `RedisOperationException`。

### 保護機制

- **非負庫存**：資料庫（`unsignedInteger`）和模型（`setStockAttribute`）確保庫存非負。
- **軟刪除**：已刪除商品無法購買，Redis 初始化排除軟刪除商品。
- **日誌限流**：使用 `RateLimiter` 限制鎖失敗日誌頻率。

## CI/CD 自動化

專案使用 GitLab CI/CD（`.gitlab-ci.yml`）實現自動化建置、測試和部署，包含以下階段：

1. **Build**：
   - 建置並推送 Docker 映像（`app`）到 GitLab 容器註冊表。
   - 快取 Composer 依賴，加速建置。

2. **Test**：
   - 在容器中運行單元測試（`php artisan test`），模擬 MySQL 和 Redis 環境。
   - 啟動佇列 Worker，測試 `UpdateItemStock` Job 的異步行為。
   - 驗證軟刪除和庫存扣減邏輯。

3. **Deploy**：
   - 透過 SSH 將 Docker Compose 配置和 `.env` 部署到生產伺服器。
   - 自動運行遷移（`migrate --force`）和啟動佇列 Worker。
   - 僅在 `main` 分支觸發。

### CI/CD 配置

1. 在 GitLab `Settings > CI/CD > Variables` 中設置：
   - `JWT_SECRET`：JWT 密鑰（生成：`php artisan jwt:secret`）
   - `APP_KEY`：Laravel 應用密鑰（生成：`php artisan key:generate`）
   - `SSH_USER`：生產伺服器 SSH 用戶名
   - `SSH_PRIVATE_KEY`：SSH 私鑰（標記為 Masked）
   - `PRODUCTION_SERVER_IP`：生產伺服器 IP
   - `CI_REGISTRY_USER`：GitLab 容器註冊表用戶
   - `CI_REGISTRY_PASSWORD`：GitLab 容器註冊表密碼
   - `CI_REGISTRY_IMAGE`：映像路徑（例如 `registry.gitlab.com/your-username/your-repo`）

2. 推送程式碼到 `main` 分支：
   ```bash
   git push origin main
   ```

3. 檢查 CI/CD 進度：
   - 前往 GitLab 的 `CI/CD > Pipelines` 查看建置、測試和部署結果。

## 測試與調試

### 運行測試

1. 進入容器：
   ```bash
   docker-compose exec app bash
   ```

2. 執行單元測試：
   ```bash
   php artisan test
   ```

3. 測試購買端點：
   - 使用 cURL：
     ```bash
     curl -X POST http://localhost/api/v1/auth/login \
          -H "Content-Type: application/json" \
          -d '{"email":"test@example.com","password":"password"}'
     ```
     獲取 Token 後：
     ```bash
     curl -X POST http://localhost/api/v1/items/1/purchase \
          -H "Authorization: Bearer <token>" \
          -H "Content-Type: application/json" \
          -d '{"quantity":3}'
     ```

4. 測試軟刪除：
   ```bash
   docker-compose exec app php artisan tinker
   App\Models\Item::find(1)->delete();
   curl -X POST http://localhost/api/v1/items/1/purchase \
        -H "Authorization: Bearer <token>" \
        -H "Content-Type: application/json" \
        -d '{"quantity":1}'
   ```
   應返回“商品 1 不存在或已被刪除”。

5. 壓力測試（可選）：
   - 安裝 [Locust](https://locust.io/)：
     ```bash
     pip install locust
     ```
   - 創建 `locustfile.py`：
     ```python
     from locust import HttpUser, task
     class ApiUser(HttpUser):
         @task
         def purchase_item(self):
             self.client.post("/api/v1/items/1/purchase", json={"quantity": 1}, headers={"Authorization": "Bearer <token>"})
     ```
   - 運行：
     ```bash
     locust -f locustfile.py --host=http://localhost
     ```
   - 訪問 `http://localhost:8089` 查看壓力測試結果。

### 檢查日誌

- 本地日誌：
  ```bash
  docker-compose exec app cat storage/logs/laravel.log
  ```
- 生產日誌：
  ```bash
  ssh $SSH_USER@$PRODUCTION_SERVER_IP 'docker-compose -f /app/laravel-api/docker-compose.yml logs app'
  ```
- 確認庫存更新（原始和新庫存）、鎖失敗或錯誤訊息。

## 環境配置

### .env 範例

```env
APP_NAME=Laravel
APP_ENV=local
APP_KEY=base64:your_app_key_here
APP_DEBUG=true
APP_URL=http://localhost
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=root
REDIS_HOST=redis
REDIS_PORT=6379
QUEUE_CONNECTION=redis
JWT_SECRET=your_jwt_secret_here
LOCK_MAX_RETRIES=5
LOCK_RETRY_DELAY_MIN=10000
LOCK_RETRY_DELAY_MAX=200000
TEST_USER_PASSWORD=password
```

### Docker Compose

`docker-compose.yml` 包含以下服務：
- `app`：Laravel 應用（PHP-FPM）
- `nginx`：Web 伺服器（端口 80）
- `mysql`：資料庫（端口 3306）
- `redis`：快取和佇列（端口 6379）

### 權限設置

若遇到儲存權限問題，運行：
```bash
docker-compose exec app chmod -R 775 storage bootstrap/cache
docker-compose exec app chown -R www-data:www-data storage bootstrap/cache
```

## 常見問題

- **佇列不運行**：
  - 確認 `.env` 中 `QUEUE_CONNECTION=redis`。
  - 啟動 Worker：
    ```bash
    docker-compose exec app php artisan queue:work redis --queue=stock_updates
    ```
- **JWT 錯誤**：
  - 確認 `JWT_SECRET` 已設置：
    ```bash
    docker-compose exec app php artisan jwt:secret
    ```
- **庫存不一致**：
  - 檢查 Redis（`docker-compose exec redis redis-cli`) 和資料庫（`docker-compose exec mysql mysql -uroot -proot laravel`）。
  - 確保遷移和種子數據正確。
- **用戶表不存在**：
  - 確保 `users` 表遷移已運行：
    ```bash
    docker-compose exec app php artisan migrate
    ```

## 未來改進

- 新增 API 限流（`throttle` 中間件）。
- 支援國際化（i18n）錯誤訊息。
- 整合日誌聚合（例如 ELK）。
- 為 `items.name` 添加索引，優化查詢性能。

## 貢獻

歡迎提交 Issue 或 Pull Request！請遵循：
1. Fork 儲存庫
2. 創建特性分支（`git checkout -b feature/your-feature`）
3. 提交變更（`git commit -m 'Add your feature'`）
4. 推送分支（`git push origin feature/your-feature`）
5. 開啟 Pull Request

## 授權

MIT License