# AGENTS.md

## 🎯 Objective

Build and maintain the Editorio WordPress plugin using clean architecture, modular design, and reusable components.

---

## 🧠 General Principles

- Follow SOLID principles
- Prefer composition over inheritance
- Keep modules decoupled
- Avoid global state when possible
- Always think in terms of WordPress hooks and lifecycle

---

## 🏗️ Architecture Guidelines

The system is modular. Each feature must belong to a module.

### Modules:

- Sources
- Collector
- Processor
- Draft
- Review
- Publisher

Each module must contain:

- Service (business logic)
- Repository (data access)
- Controller (REST or admin actions)
- Hooks (WordPress integration)

---

## 📁 Suggested Folder Structure

```
editorio/
├── includes/
│   ├── Modules/
│   │   ├── Sources/
│   │   ├── Collector/
│   │   ├── Processor/
│   │   ├── Draft/
│   │   ├── Review/
│   │   └── Publisher/
│   ├── Common/
│   └── Helpers/
├── admin/
│   ├── UI/
│   └── Assets/
├── public/
├── vendor/
├── editorio.php
```

---

## 🔌 WordPress Integration Rules

- Use custom post types ONLY if necessary
- Prefer custom tables for structured data (feeds, drafts)
- Use WP REST API for communication with React UI
- Use nonces and capability checks for security

---

## ⚙️ Coding Standards

- PHP: PSR-12
- JS: ESNext + modular
- Use TypeScript if possible
- Use dependency injection where applicable

---

## 🧩 Feature Development Rules

When implementing a feature:

1. Identify the module
2. Create service class
3. Create data structure (DB or WP)
4. Expose via REST endpoint
5. Connect to UI

---

## 🧪 Draft Generation Rules

- Do not directly publish content
- Always create a draft first
- Draft must be editable before publishing
- Preserve original source link for traceability

---

## 🔄 Feed Processing Rules

- Avoid duplicate content
- Normalize HTML content
- Strip unnecessary markup
- Keep title and main body

---

## 🧠 AI Integration (Future)

- AI should enhance, not replace structure
- Always keep raw content stored
- AI output must be reviewable

---

## 🚫 Anti-Patterns to Avoid

- Massive classes (God objects)
- Mixing UI and business logic
- Direct DB queries without abstraction
- Hardcoding values

---

## 🧭 Decision Guidelines

If uncertain:

- Prefer simpler implementation first (MVP)
- Avoid premature optimization
- Design for extensibility

---

## 📌 Output Expectations (for AI)

When generating code:

- Explain structure briefly
- Keep functions small and focused
- Use clear naming
- Avoid unnecessary complexity
