# Overview
```txt

                  ┌────────────────────────────────────────────────────┐
                  │              Codex (container Apache/PHP)          │
                  │                                                    │
  ┌───────────┐   │   ┌──────────────────┐       ┌──────────────────┐  │   ┌────────────────┐
  │  Browser  ├───►   │  Pages & API     │ ────► │  Business Logic  │  ├───► codex.sqlite   │
  └───────────┘   │   │  reader/library/ │       │  Items,          │  │   └────────────────┘
                  │   │  admin.php       │       │  LibraryScanner, │  │
                  │   │  api/index.php   │       │  ItemEnrichment, │  │   ┌────────────────┐
                  │   └──────────────────┘       │  Auth...         │  ├───► Libraries      │
                  │                              └──────────────────┘  │   │ (files)        │
                  │                                                    │   └────────────────┘
                  └────────────────────────────────────────────────────┘
```

## Library Synchronization Pipeline
```txt
  ┌────────────────┐   ┌──────────────────────┐   ┌──────────────────────┐   ┌──────────────────────┐
  | Browse folders ├───► Database comparaison ├───► Extraction           ├───►   Database update    |
  └────────────────┘   | new?                 |   | metadata + covers    |   |                      |
                       | modifid (date/size)? |   └──────────────────────┘   └──────────────────────┘
                       | unchanged?           |
                       └─────────┬────────────┘
                                 | no change
                                 ▼
                      File is ignored, without reading its contents
```

## Directing readers according to the format
```txt
                              ┌───────────────────┐
                              | Opening an object |
                              └─┬───────────────┬─┘
                                |               |
                               PDF            OTHER
                                |               |
               ┌────────────────▼───┐       ┌───▼────────────────┐
               | PDF.js             |       | Server rendering   |
               | canvas + clickable |       | 1 image per page   |
               | links              |       | (ItemPages.php)    |     
               └────────────────┬───┘       └───┬────────────────┘
                                |               |
                           PDF.js error         |
                                |               |
                              ┌─▼───────────────▼─┐
                              |   Page displayed  |
                              └───────────────────┘
```

## Recursive editor/collection navigation
```txt
                    ┌─────────────────────────────────────────┐
                    | Are there any subfolders at this level? |
                    └─────┬─────────────────────────────┬─────┘
                          |                             |
                         YES                            NO
                          |                             |
              ┌───────────▼─────────────┐ ┌─────────────▼───────────┐
              | Tile grid               | | Paginated list of items |
              | click = one level down  | | in the folder           |
              └───────────┬─────────────┘ └─────────────────────────┘
                          |
                          └───► (Go back to the top, one level deeper)
```

# Database schema
```txt
      LIBRARIES                         ITEMS                             SERIES
      ┌────────────────┐1              N┌────────────────────────┐N      1┌────────────────┐
      | id          PK ├────────────────►id                  PK  ├────────► id          PK |
      | name           |                | type                   |        | name           |
      | path        UK |                | title                  |        | type           |
      | type           |                | path                UK |        | cover_path     |
      | last_synced_at |                | format                 |        └────────────────┘
      └──────┬─────────┘                | cover_path             |
             |1                         | publisher              |
             |                          | library_id          FK |
LIBRARY_JOBS |1                         | series_id           FK |
┌────────────▼───────────┐              | metadata_checked_at    |
| library_id       PK/FK |              | file_size, file_mtime  |
| job_type               |              | added_at               |
| status                 |              └────┬───┬───────┬───┬───┘
| done, total            |                   |   |       |   |N
| current_item           |                   |   |       |   └───────────────────────────────────────┐
└────────────────────────┘              1:0/1|   |       └──────────────────────────┐                | 
                                         ┌───┘   └───────────┐                      |                |
                           COMIC_DETAILS |      EBOOK_DETAILS|      MAGAZINE_DETAILS|      ITEM_TAGS | 
                           ┌─────────────▼──┐   ┌────────────▼───┐  ┌───────────────▼──┐   ┌─────────▼──────┐
                           | item_id  PK/FK |   | item_id  PK/FK |  | item_id    PK/FK |   | item_id  PK/FK |
                           | writer         |   | author         |  | issue_date       |   | tag_id   PK/FK |
                           | ...            |   | isbn           |  | frequency        |   └─────────┬──────┘
                           └────────────────┘   | language       |  └──────────────────┘             |
                                                └────────────────┘                              TAGS |N
                                                                                                ┌────▼────┐
                                                                                                | id   PK |
                                                                                                | name UK |
                                                                                                └─────────┘
USERS                          USER_LIBRARIES
┌────────────────┐1           N┌───────────────────┐           
| id          PK ├─────────────► user_id     PK/FK |N            1
| username    UK |             | library_id  PK/FK ◄────────────── LIBRARIES
| role           |             └───────────────────┘
| status         |                 READING_PROGRESS
| totp_secret    |1               N┌─────────────────────┐N            1
| mfa_required   ├─────────────────► user_id       PK/FK ◄────────────── ITEMS
└────────────────┘                 | item_id       PK/FK |
                                   | position            |
                                   | total_pages         |
                                   | completed_at        |
                                   └─────────────────────┘



  Standalone tables (no relationships):
  
  SETTINGS                      LOGIN_ATTEMPTS
  ┌────────────────┐            ┌─────────────────┐
  | key       PK   |            | ip           PK |
  | value          |            | count, last_at  |
  └────────────────┘            └─────────────────┘
```