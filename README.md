# Laravel High Concurrency API Example

This project demonstrates a scalable RESTful API built with Laravel, leveraging **Redis atomic operations** for handling high-concurrency scenarios (e.g., inventory management), **JWT** for secure authentication, and **Docker** with **GitLab CI/CD** for automated deployment.

## Features
- RESTful API design with clear endpoints
- JWT-based user authentication and authorization
- Redis atomic operations (`WATCH`, `MULTI`, `EXEC`, `SETNX`) to prevent race conditions
- Dockerized environment for consistent development and production
- Automated build, test, and deployment via GitLab CI/CD
- Unit and feature tests for code quality
- Scalable architecture for high-performance applications

## Tech Stack
- **Backend**: Laravel 10.x (PHP 8.2+)
- **Database**: MySQL 8.0
- **Cache/Atomic Operations**: Redis
- **Authentication**: Tymon/JWT-Auth
- **Containerization**: Docker, Docker Compose
- **CI/CD**: GitLab CI/CD
- **Web Server**: Nginx

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
   docker-compose up -d
   docker-compose exec app php artisan jwt:secret
   ```
   Copy the generated JWT_SECRET to `.env`.
3. Build and run containers:
   ```bash
   docker-compose up --build -d
   ```
4. Install dependencies:
   ```bash
   docker-compose exec app composer install
   ```
5. Run database migrations:
   ```bash
   docker-compose exec app php artisan migrate
   ```

### Database Seeding
1. Run the seeder to populate test data:
   ```bash
   docker-compose exec app php artisan db:seed
   ```

## API Endpoints
Accessible via `http://localhost`.

### Authentication
- **POST /api/auth/register**
  - Body: `name`, `email`, `password`
  - Description: Register a new user
- **POST /api/auth/login**
  - Body: `email`, `password`
  - Description: Authenticate user and return JWT token
- **POST /api/auth/me** (Requires `Authorization: Bearer <token>`)
  - Description: Get authenticated user details

### Items (High Concurrency Example)
- **GET /api/items**
  - Description: List all items
- **GET /api/items/{id}**
  - Description: Get a single item by ID
- **POST /api/items/{id}/purchase** (Requires `Authorization: Bearer <token>`)
  - Body: `quantity` (integer)
  - Description: Purchase an item, using Redis to prevent overselling

## High Concurrency Solution
The `ItemService` uses Redis `WATCH`, `MULTI`, `EXEC`, and `SETNX` for optimistic locking:
1. Acquires a lock using `SETNX`
2. Watches the inventory key with `WATCH`
3. Checks stock availability
4. Executes atomic stock deduction with `MULTI`/`DECRBY`/`EXEC`
5. Updates database stock if successful

## DevOps
- **Docker**: Consistent environment via `Dockerfile` and `docker-compose.yml`
- **GitLab CI/CD**: Automates build, test, and deployment (see `.gitlab-ci.yml`)

### CI/CD Setup
1. Push to GitLab repository
2. Set GitLab CI/CD variables:
   - `JWT_SECRET`: From `.env`
   - `PRODUCTION_SERVER_IP`: Production server IP
   - `SSH_USER`: SSH username
   - `SSH_PRIVATE_KEY`: SSH private key (mark as masked)
   - `CI_REGISTRY_USER`, `CI_REGISTRY_PASSWORD`: Provided by GitLab

## Running Tests
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
