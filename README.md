# Laravel 高併發 API 範例

此專案展示了一個使用 Laravel 建構的、可擴展的 RESTful API，它結合了 **Redis 原子操作** 以處理高併發場景（例如庫存管理），**JWT 認證** 以確保安全性，以及 **Docker** 與 **GitLab CI/CD** 以實現自動化部署。此版本著重於從程式碼層面到基礎設施層面的深度優化，旨在提供生產級別的高可用性。

## 功能特性

- **RESTful API 設計**：清晰的 API 端點，引入 `/v1` 版本控制。
- **JWT 身份驗證與授權**：增強型 JWT 安全性，包含 `refresh_token` 機制。
- **Redis 原子操作** (`WATCH`, `MULTI`, `EXEC`, `SETNX`)：精確控制高併發下的庫存扣減，防止超賣。
- **強化 Redis 鎖定邏輯**：引入指數退避 (Exponential Backoff) 重試機制，減少合法請求因瞬時鎖競爭失敗被拒。
- **異步資料庫更新**：將 Redis 庫存扣減後的資料庫更新操作異步化至 Laravel Queue，提升 API 響應速度。
- **集中化錯誤處理與日誌記錄**：自定義 Exception 類別 (`RedisOperationException`, `StockInsufficientException`) 提供精細錯誤回傳與豐富日誌上下文。
- **服務解耦 (Repository 模式)**：將資料庫與 Redis 操作抽象化，提升可測試性與可維護性。
- **資料庫與 Redis 數據最終一致性**：透過 Laravel Schedule 定時同步資料庫庫存回 Redis。
- **Docker 映像優化**：採用多階段建置 (Multi-stage Build) 和 `.dockerignore` 減少映像體積，提升部署效率和安全性。
- **CI/CD 自動化部署**：透過 GitLab CI/CD 實現自動化建置、測試與部署，並考慮集成 Kubernetes (K8s) + Helm。
- **安全變數管理**：敏感資訊（如資料庫密碼）透過 Docker Secrets 和 CI/CD 安全變數管理。
- **輸入驗證改進**：利用 Laravel FormRequest 集中處理請求驗證與授權。
- **擴展測試覆蓋率**：包含單元測試與功能測試，特別是併發行為測試。
- **API 限流與防禦**：應用 Laravel `throttle` 中間件，並建議 Nginx 層面的限流。
- **國際化 (i18n) 支援**：所有用戶可見訊息皆可本地化。
- **性能快取**：針對讀取頻繁的數據採用 Laravel 快取機制。
- **系統監控考量**：建議整合 Prometheus、Grafana、Laravel Telescope 及日誌聚合系統 (ELK/Loki)。
- **多租戶考量**：預留多租戶架構設計（數據表 `tenant_id`、Redis 鍵前綴）。

## 技術棧

-   **後端**: Laravel 10.x (PHP 8.2+)
-   **資料庫**: MySQL 8.0
-   **快取/原子操作**: Redis
-   **身份驗證**: Tymon/JWT-Auth
-   **容器化**: Docker, Docker Compose
-   **CI/CD**: GitLab CI/CD
-   **Web 服務器**: Nginx

## 快速開始

### 前置條件

-   已安裝 Docker 和 Docker Compose
-   GitLab 帳號（用於 CI/CD 設定）

### 本地設置 (使用 Docker Compose)

1.  複製儲存庫：
    ```bash
    git clone [https://github.com/BpsEason/laravel-high-concurrency-api.git](https://github.com/BpsEason/laravel-high-concurrency-api.git)
    cd laravel-high-concurrency-api
    ```
2.  創建 `.env` 檔案並啟動服務，生成 JWT Secret：
    ```bash
    cp .env.example .env
    docker-compose up -d mysql redis # 先啟動依賴服務
    docker-compose up --build -d # 建置並啟動所有容器
    docker-compose exec app php artisan jwt:secret
    ```
    將生成的回覆中的 `JWT_SECRET` 複製到您的 `.env` 檔案中。
3.  安裝 PHP 依賴：
    ```bash
    docker-compose exec app composer install
    ```
4.  運行資料庫遷移：
    ```bash
    docker-compose exec app php artisan migrate
    ```

### 資料庫填充

1.  運行填充器以填充測試數據：
    ```bash
    docker-compose exec app php artisan db:seed
    ```

## API 端點

所有 API 端點可透過 `http://localhost/api/v1` 訪問。

### 身份驗證 (`/api/v1/auth`)

-   **POST /api/v1/auth/register**
    -   Body: `name`, `email`, `password`
    -   描述: 註冊新用戶
-   **POST /api/v1/auth/login**
    -   Body: `email`, `password`
    -   描述: 驗證用戶並返回 JWT Token
-   **POST /api/v1/auth/me** (需 `Authorization: Bearer <token>`)
    -   描述: 獲取已認證用戶的詳細信息
-   **POST /api/v1/auth/logout** (需 `Authorization: Bearer <token>`)
    -   描述: 登出用戶並使當前 Token 失效
-   **POST /api/v1/auth/refresh** (需 `Authorization: Bearer <token>`)
    -   描述: 刷新 Access Token

### 商品管理 (`/api/v1/items`) (高併發範例)

-   **GET /api/v1/items**
    -   描述: 列出所有商品 (包含快取)
-   **GET /api/v1/items/{id}**
    -   描述: 根據 ID 獲取單個商品
-   **POST /api/v1/items/{id}/purchase** (需 `Authorization: Bearer <token>`)
    -   Body: `quantity` (整數)
    -   描述: 購買商品，使用 Redis 原子操作和異步佇列防止超賣。

## 高併發解決方案

`ItemService` 結合 Redis 的 `WATCH`, `MULTI`, `EXEC` 實現樂觀鎖定，並使用 `SETNX` 實現分布式鎖，同時引入了重試機制和異步資料庫更新：

1.  **獲取鎖定**：使用 `SETNX` 嘗試獲取分布式鎖，並在失敗時進行重試 (Exponential Backoff)。
2.  **監聽庫存鍵**：在執行事務前，使用 `WATCH` 監聽 Redis 庫存鍵。
3.  **檢查庫存**：檢查當前庫存是否滿足購買數量。
4.  **原子扣減**：使用 `MULTI`/`DECRBY`/`EXEC` 執行原子庫存扣減事務。
5.  **異步更新資料庫**：如果 Redis 扣減成功，則分派一個 Laravel Job 到佇列中，異步更新 MySQL 資料庫。

## DevOps 實踐

-   **Docker**: 通過優化後的 `Dockerfile` 和 `docker-compose.yml` 提供一致的開發和生產環境，採用多階段建置減小映像體積。
-   **GitLab CI/CD**: 自動化建置、測試和部署流程 (詳見 `.gitlab-ci.yml`)，建議在生產環境中考慮 Kubernetes (K8s) 和 Helm 進行部署。

### CI/CD 設定

1.  將程式碼推送到 GitLab 儲存庫。
2.  在 GitLab CI/CD 設定中設置以下安全變數：
    -   `JWT_SECRET`: 應用程式的 JWT Secret。
    -   `PRODUCTION_SERVER_IP`: 生產伺服器 IP (如果使用 SSH 部署)。
    -   `SSH_USER`: SSH 用戶名 (如果使用 SSH 部署)。
    -   `SSH_PRIVATE_KEY`: SSH 私鑰 (標記為 Masked，如果使用 SSH 部署)。
    -   `CI_REGISTRY_USER`, `CI_REGISTRY_PASSWORD`: GitLab 提供的容器註冊表憑證。
    -   `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `REDIS_PORT`: 資料庫和 Redis 連線憑證。

## 運行測試

進入應用程式容器：
```bash
docker-compose exec app bash