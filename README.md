# Editorio

Automates news creation in WordPress by importing RSS feeds and transforming them into structured draft posts.

---

## 🎯 Purpose

Editorio is a WordPress plugin designed to automate the process of news aggregation and draft generation.

It allows users to register RSS sources, collect content, and transform it into editable drafts inside WordPress.

---

## 🧠 Core Concept

Editorio acts as a **content pipeline**:

Sources → Collection → Processing → Draft → Review → Editor

---

## 🏗️ Architecture Overview

The plugin is structured into modular layers:

- **Sources Module** → manages RSS feeds
- **Collector Module** → fetches feed data
- **Processor Module** → transforms raw data into content
- **Draft Module** → builds structured drafts
- **Review Module** → UI for approval/discard
- **Publisher Module** → sends content to WP editor

---

## 📦 Features

### ✅ Implemented (MVP Goal)

- RSS source registration
- Feed fetching
- Draft generation (basic)
- Draft review screen
- Send draft to WordPress editor

### 🧪 Planned

- Content filtering rules
- Duplicate detection
- Multi-source merging (same topic)
- AI-assisted rewriting
- Scheduling (cron)
- Tag/category auto-detection

---

## 🔄 Workflow

### 1. Register Sources
User adds RSS feed URLs.

### 2. Fetch Content
System retrieves items from feeds.

### 3. Process Content *(WIP)*
- Normalize data
- Remove duplicates
- Prepare structure

### 4. Generate Draft
Create a structured draft article.

### 5. Review
User can:
- Approve
- Discard
- Send to editor

### 6. Publish
Draft becomes a WordPress post.

---

## 🧱 Data Model (Conceptual)

### Source
- id
- name
- url
- active

### FeedItem
- id
- source_id
- title
- content
- link
- published_at

### Draft
- id
- title
- content
- status (pending, approved, discarded)
- created_at

---

## ⏱️ Execution Modes

- Manual trigger
- Scheduled (cron) *(planned)*

---

## 🛠️ Tech Stack

- PHP (WordPress plugin)
- JavaScript (React for admin UI)
- WordPress REST API
- WP Cron (planned)

---

## 📌 Roadmap

- [ ] Sources UI
- [ ] Feed ingestion service
- [ ] Draft builder
- [ ] Review interface
- [ ] Editor integration
- [ ] Deduplication engine
- [ ] AI integration
- [ ] Scheduling system

---

## 🤝 Contributing

This project is being developed with AI-assisted workflows. See `AGENTS.md` for development guidelines.
