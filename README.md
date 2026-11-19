# Poultry Hisab — Simple Daily Farm Register

A very simple poultry farm accounting app for a village farmer — usable without
any technical knowledge. Marathi (मराठी) is the default language; English is
available via the **EN | मराठी** toggle in the top bar and on the login screen.

| Layer    | Stack                                              |
| -------- | -------------------------------------------------- |
| Frontend | React (Vite), React Router, Axios, Bootstrap 5     |
| Backend  | Core PHP 8 REST API, PDO, session auth             |
| Database | MySQL 8 (InnoDB)                                   |

## What the app does — exactly 5 things

| Menu | What it does |
| ---- | ------------ |
| **Dashboard** | Today's produced / received / sold / damaged eggs, egg stock, today's income & expense — big number cards. |
| **Daily Egg Entry** | One form: date, opening stock, produced, received, sold, damaged, notes. Closing stock is auto-calculated. Editable later. |
| **Daily Report** | Pick a date → see that day's numbers. Recent days table with Edit buttons. |
| **Monthly Report** | Pick month + year → totals (produced, received, sold, damaged, month-end stock, income, expense, net) + day-wise table + Print/PDF. |
| **Money / हिसाब** | Income & expense register: date, type, category, description, amount, payment mode (Cash/UPI/Bank). Live balance + history, edit & delete. |

## How it works (internals)

- `layer_daily_records` is the single source of truth for egg stock.
  `closing = opening + produced + received − sold − damaged` is stored per day.
- Editing an old day **recalculates opening/closing for every later day**
  (cascade) inside one transaction — the chain can never go stale.
- `egg_stock_movements` is kept as an audit trail only and is re-synced to the
  record chain after each save.
- `money_transactions` holds one row per income/expense entry. The existing
  `expenses` rows were migrated into it (see `database/money_migration.sql`).
- Old module tables (customers, sales, batches, inventory, …) are still in the
  database with all their data intact — nothing was dropped — but no API route
  or screen uses them anymore.

## API surface (trimmed)

Base URL `/api`, JSON envelope `{ success, data, error }`.

| Method | Endpoint | Purpose |
| ------ | -------- | ------- |
| POST | `/auth/login` `/auth/logout` | session login (admin / admin123 seed) |
| GET  | `/auth/me` | current user |
| GET/POST | `/layers/records` | list / upsert daily egg record (cascades later days) |
| GET/DELETE | `/layers/records/{date}` | read / delete one day |
| GET  | `/layers/egg-stock` | current stock + movement history |
| GET/POST | `/money` | list w/ income-expense-balance totals / create entry |
| PUT/DELETE | `/money/{id}` | update / delete entry |
| GET  | `/money/categories` | income & expense category lists |

## Setup

**Database**
```bash
mysql -u root -p < database/database.sql      # base schema + admin user
mysql -u root -p poultry_farm < database/money_migration.sql
```

**Backend** (dev):
```bash
php -S localhost:8000 -t backend backend/router.php
```

**Frontend**:
```bash
cd frontend
npm install
npm run dev      # :5173, proxies /api → :8000
npm run build    # production bundle → dist/
```

Login: **admin / admin123**
