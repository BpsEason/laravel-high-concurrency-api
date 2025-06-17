是的，`README.md` 文件也需要更新，以反映您專案結構和 CI/CD 流程的最新變化。特別是 API 端點、高併發解決方案的描述以及本地設置步驟。

以下是針對 `README.md` 的修改建議和完整程式碼：

### **`README.md` (專案說明文件)**

**主要修改：**
* **API 端點更新：** 將所有 `/api/` 路徑修改為 `/api/v1/` 以符合新的版本控制。
* **認證端點更新：** 增加 `logout` 和 `refresh` 端點。
* **高併發解決方案描述更新：**
    * 明確指出引入了 `Repository` 模式。
    * 強調了 Redis 原子操作後，**資料庫更新是異步透過佇列處理的**。
    * 提及了 Redis 鎖的重試機制。
    * 強調現在會拋出具體的異常。
* **本地設置步驟更新：**
    * 在 `composer install` 之後，提醒用戶啟動佇列 worker。
    * 將 `php artisan migrate` 更新為 `php artisan migrate:fresh --seed`，因為現在測試會用到初始化數據。
* **技術棧更新：**
    * 如果考慮使用 Laravel Horizon，可以將其加入技術棧。
    * 確保 Mockery 也被納入 `require-dev` 中。

```markdown
# Laravel High Concurrency API Example

This project demonstrates a scalable RESTful API built with Laravel, leveraging **Redis atomic operations** for handling high-concurrency scenarios (e.g., inventory management), **JWT** for secure authentication, and **Docker** with **GitLab CI/CD** for automated deployment.

## Features
- RESTful API design with clear endpoints and **API Versioning (v1)**.
- JWT-based user authentication and authorization, including **token refresh and logout**.
- Redis atomic operations (`WATCH`, `MULTI`, `EXEC`, `SETNX`) with **retry mechanisms for distributed locks** to prevent race conditions.
- **Asynchronous database updates via Laravel Queues** for improved performance under high load.
- **Repository pattern** for better separation of concerns between business logic and data access.
- **Custom exception handling** for specific business errors like insufficient stock or Redis operation failures.
- Dockerized environment for consistent development and production.
- Automated build, test, and deployment via GitLab CI/CD.
- Unit and feature tests for code quality.
- Scalable architecture for high-performance applications.

## Tech Stack
- **Backend**: Laravel 10.x (PHP 8.2+)
- **Database**: MySQL 8.0
- **Cache/Atomic Operations**: Redis, Predis
- **Authentication**: Tymon/JWT-Auth
- **Containerization**: Docker, Docker Compose
- **CI/CD**: GitLab CI/CD
- **Web Server**: Nginx
- **Queue Monitoring (Optional)**: Laravel Horizon (if implemented)
- **Testing**: PHPUnit, Mockery

## Getting Started

### Prerequisites
- Docker and Docker Compose installed
- GitLab account for CI/CD setup

### Local Setup with Docker Compose
1. Clone the repository:
   ```bash
   git clone https://github.com/BpsEason/laravel-high-concurrency-api.git
   cd laravel-high-concurrency-api
   ```
2. Create `.env` file and generate JWT secret:
   ```bash
   cp .env.example .env
   docker-compose up -d # 先啟動服務以執行命令
   docker-compose exec app php artisan jwt:secret
   ```
   Copy the generated `JWT_SECRET` to `.env`.
3. Build and run containers:
   ```bash
   docker-compose up --build -d
   ```
4. Install dependencies:
   ```bash
   docker-compose exec app composer install
   ```
5. Run database migrations and seed initial data:
   ```bash
   docker-compose exec app php artisan migrate:fresh --seed
   ```
6. **Start the Queue Worker (in a separate terminal):**
   ```bash
   docker-compose exec app php artisan queue:work
   ```

## API Endpoints
Accessible via `http://localhost`.

### Authentication
- **POST /api/v1/auth/register**
  - Body: `name`, `email`, `password`
  - Description: Register a new user
- **POST /api/v1/auth/login**
  - Body: `email`, `password`
  - Description: Authenticate user and return JWT token
- **POST /api/v1/auth/me** (Requires `Authorization: Bearer <token>`)
  - Description: Get authenticated user details
- **POST /api/v1/auth/logout** (Requires `Authorization: Bearer <token>`)
  - Description: Invalidate the current JWT token
- **POST /api/v1/auth/refresh** (Requires `Authorization: Bearer <token>`)
  - Description: Get a new JWT access token

### Items (High Concurrency Example)
- **GET /api/v1/items**
  - Description: List all items
- **GET /api/v1/items/{id}**
  - Description: Get a single item by ID
- **POST /api/v1/items/{id}/purchase** (Requires `Authorization: Bearer <token>`)
  - Body: `quantity` (integer)
  - Description: Purchase an item, using Redis atomic operations and queues to prevent overselling and handle high concurrency.

## High Concurrency Solution
The `ItemService` leverages a **Repository Pattern** and uses Redis `WATCH`, `MULTI`, `EXEC`, and `SETNX` for optimistic locking and atomic operations:
1.  **Acquires a distributed lock** using `SETNX` with a **retry mechanism** for robustness in high contention scenarios.
2.  **Watches the inventory key** with `WATCH` to detect changes during the transaction.
3.  Checks stock availability in Redis.
4.  **Executes atomic stock deduction** with `MULTI`/`DECRBY`/`EXEC`.
5.  If Redis operation is successful, **dispatches an asynchronous job to the queue** (`UpdateItemStock` Job) to update the database stock.
6.  **Throws specific exceptions** (`StockInsufficientException`, `RedisOperationException`) for clearer error handling.

## DevOps
- **Docker**: Consistent environment via `Dockerfile` and `docker-compose.yml`.
- **GitLab CI/CD**: Automates build, test, and deployment (see `.gitlab-ci.yml`).

### CI/CD Setup
1. Push to GitLab repository
2. Set GitLab CI/CD variables:
   - `JWT_SECRET`: From `.env`
   - `PRODUCTION_SERVER_IP`: Production server IP
   - `SSH_USER`: SSH username
   - `SSH_PRIVATE_KEY`: SSH private key (mark as masked)
   - `CI_REGISTRY_USER`, `CI_REGISTRY_PASSWORD`: Provided by GitLab

## Running Tests
Ensure your Docker containers are running and the queue worker is active for full feature test coverage.

Enter the app container:
```bash
docker-compose exec app bash
```
Run all tests:
```bash
php artisan test
```
Run unit tests only:
```bash
php artisan test --testsuite=Unit
```

## Contributing
1. Fork the repository
2. Create a feature branch (`git checkout -b feature/new-feature`)
3. Commit changes (`git commit -m "Add new feature"`)
4. Push to the branch (`git push origin feature/new-feature`)
5. Create a Pull Request

## License
MIT License
```