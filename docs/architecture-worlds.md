# Supply System Architecture — Two Worlds, Connections & ICS/PAR

> Context doc for `supplydbms` (Laravel 12). Explains the legacy data the RPCPPE/RPCSP
> module reads from. The legacy supply system is split into **two logical table-sets**
> ("World 1" and "World 2") that live in the **same** SQL Server database. They share
> almost nothing and use different conventions — this is the main source of confusion.

---

## 1. Database connections

There are three databases with overlapping names. **Do not conflate them.**

| # | Server / Database | What it is | Used by |
|---|---|---|---|
| 1 | `192.168.1.62` / **EstockDbsv2** | Legacy **production** (old ASP.NET WebForms app) | legacy app conn `sisv2con` |
| 2 | `192.168.1.62` / **EstockDbsv2_devdev** | Remote dev copy — **STALE** (missing new tables; sync later) | `supply-backend/.env` |
| 3 | **localhost** / **EstockDbsv2_devdev** | **Source of truth** for this project | **`supplydbms/.env`** |

- This project (`supplydbms`) connects to **#3 (localhost)** via Windows auth:
  ```
  DB_CONNECTION=sqlsrv
  DB_HOST=localhost
  DB_PORT=1433
  DB_DATABASE=EstockDbsv2_devdev
  DB_USERNAME=      # blank -> Integrated Security (Windows auth)
  DB_PASSWORD=
  ```
- Both **World 1 and World 2 live inside this ONE database** (`EstockDbsv2_devdev`).
  They are *not* separate database connections — just two unrelated groups of tables.
- The legacy app additionally used a second connection `conpurchaseorder` →
  `PO_DBS` @ `192.168.1.62` (read-only, purchase-order lookups only).
- New tables created by this project (migrations): `ppe_types`, `articles`, `rpcppe`,
  `app_settings`, `inventory_transfer_reports`, `inventory_transfer_report_items`.

---

## 2. The two worlds

```
                 DATABASE: EstockDbsv2_devdev (localhost)
  ┌───────────────────────────────┬────────────────────────────────────┐
  │  WORLD 1 — CONSUMABLES/SUPPLY  │  WORLD 2 — OFFICE SUPPLIES/PROPERTY │
  │  (General, Drugs, Vaccines)    │  (MR = Memorandum Receipt)         │
  ├───────────────────────────────┼────────────────────────────────────┤
  │ Category  ref_commoditycategory│ Category  tbl_mrcategory            │
  │ Item      ref_commoditymasterlist│ Item    tbl_commodity /           │
  │                                │           tbl_mrcommodity           │
  │ Unit      ref_uom              │ (uom via ref_uom)                   │
  │ Location  ref_commoditylocation│                                     │
  │ Stock     trans_gen_inventory  │ Stock     tbl_mr_Maininventory      │
  │ Issuance  RIS/PTR/ITR/RSMI     │ Issuance  ICS / PAR / RIS / PTR     │
  │           trans_gen_* / trans_rsmi* │       tbl_mrics* / tbl_mrpar* / │
  │                                │           tbl_mr_* / tbl_mr_allocation│
  │ Views  vw_scommoditylist,      │ Views  vw_mrinventorylist_withoum,  │
  │        vw_geninventory         │        vw_mrissuedlist, vw_MainInventory│
  └───────────────────────────────┴────────────────────────────────────┘
```

### World 1 — Consumables / Supply engine
General inventory for drugs, vaccines, medical/dental/lab supplies, equipment, etc.
- **`ref_commoditycategory`** — "Type of Commodity" / category (`comcategid`, `categ_code`,
  `categ_name`, `categ_main`). `categ_main` groups categories into modules:
  `1` = Drugs, `2` = General supplies, `3` = Vaccines, `NULL` = unassigned.
- **`ref_commoditymasterlist`** — the commodity/item (`comid`, `com_code`, `com_desc`,
  `com_categcode` → category, `com_uomid` → unit, `com_isactive`, `com_void`).
- **`trans_gen_inventory`** — actual stock records (`gid` PK, `stockno`,
  `typeofcomid` = category, `commodityid` = item, `origqty`, **`unitcost`**,
  `dateaquired`, `locationid`, `void`, …). Written by SP `trans_gen_inventory_IU`.
- **Key for reports:** `unitcost` lets us classify equipment by the ₱50k threshold.
  Medical Equipment (`categ_code 10605110`) lives ONLY here — not in World 2.

### World 2 — Office Supplies / Property engine (MR)
Property custodianship: issuing items to employees and tracking who holds them.
- **`tbl_mrcategory`** — office-supply categories (`Category_ID`, `Category_Name`):
  IT Supplies, Office Supplies, Other Office Supplies, IEC Materials, Spare Parts,
  Maintenance. (No medical/equipment category — those are World 1.)
- **`tbl_commodity`** / **`tbl_mrcommodity`** — office-supply items (`OS_code`/`commodityID`,
  `com_desc`, `typecomid`/`mr_categoryID`).
- **`tbl_mr_Maininventory`** — office-supply stock (`ID` PK, `commodity_ID`, `category_ID`,
  `qty`, **`u_cost`**, `amt`, `stock_no`, `source`, `void`, …).
- **`tbl_mr_allocation`** — allocation of stock to employees.
- Its whole purpose is **custodianship** (who holds what) — a concept World 1 lacks.

> **The legacy "General Inventory Custodian" page in World 1 is a dead prototype**
> (`tbl_trans_ics` has 1 row; `tbl_trans_icslist` doesn't exist). All *working*
> custody/issuance lives in World 2 (`tbl_mrics*`, `tbl_mrpar*`).

---

## 3. ICS and PAR (the accountability documents)

Both are COA accountability forms that record issuing property to an **end-user** and
establish who is accountable. In this system they exist **only in World 2**.

| | **PAR** — Property Acknowledgment Receipt | **ICS** — Inventory Custodian Slip |
|---|---|---|
| Used for | **PPE** (unit cost **≥ ₱50,000**) | **Semi-expendable** (unit cost **< ₱50,000**) |
| COA report | **RPCPPE** (Appendix 73) | **RPCSP** (Annex A.8) / **RegSPI** (Annex A.4) |
| Header tables | `tbl_mrpar` (`ID`, `PAR_No`, `emp_no`, `void`) | `tbl_mrics` (`ID`, `ICS_No`, `emp_no`, `void`) |
| Line tables | `tbl_mrparlist` | `tbl_mricslist` |
| Line columns | `par_ID`, `inventory_ID` (→ `tbl_mr_Maininventory.ID`), `qty`, `unitofmeasure`, `u_cost`, `total_cost`, `description`, `inventory_no`, `date_acquired`, `emp_no` | same **plus `e_life`** (estimated useful life) |

- **PAR = PPE, ICS = semi-expendable.** This is how the system distinguishes the two.
- Both link a stock row (`inventory_ID` → `tbl_mr_Maininventory.ID`) to an accountable
  officer (`emp_no`).
- `e_life` (estimated useful life) exists on **ICS lines only** — it's a semi-expendable
  attribute (PPE uses depreciation instead).
- There is **no PAR/ICS in World 1** — World 1 equipment has no slip, so it must be
  classified by `unitcost` (see §4).

---

## 4. How the RPCPPE/RPCSP module uses all this

Classification is **two independent decisions**:

1. **Nature gate — is it property or a consumable?** (consumables → RPCI, excluded here)
2. **PPE vs Semi-expendable** — per item:

```
For each item:
  • issued on PAR  ........................ PPE            → RPCPPE
  • issued on ICS  ........................ Semi-expendable → RPCSP / RegSPI
  • no slip (World 1 equip. + un-issued) .. by unit cost:
        u_cost >= 50,000 .................. PPE            → RPCPPE
        u_cost <  50,000 .................. Semi-expendable
             u_cost >  5,000 ............. high-value semi
             u_cost <= 5,000 ............. low-value semi
```

- **Thresholds (₱50,000 / ₱5,000) are config** in `app_settings`, not hard-coded
  (the PPE threshold changed ₱15k → ₱50k in 2022, COA Circular 2022-004).
- **Article (account/nature)** and **PPE-vs-semi (cost)** are *orthogonal* — the same
  article (e.g. Medical Equipment) can have items on both sides of ₱50k. Classification
  is **not** stored on the article; it's derived per item.
- **Count BOTH issued and unissued** (physical count = total accountability).
  Issued vs unissued is a separate axis (`custody`); it just routes semi-expendable to
  different reports: unissued → RPCSP, issued → RegSPI.
- **Watch-out:** "…for Distribution" items are *Inventory Held for Distribution* (RPCI),
  **not** PPE — despite high value they fail the "held for use / not for distribution"
  test.
- **ID ambiguity:** World 1 `trans_gen_inventory.gid` and World 2 `tbl_mr_Maininventory.ID`
  are separate number spaces. When `rpcppe` pulls from both, tag rows with a `source`
  discriminator so `item_id` stays unambiguous.

### Current state of the module
- `RPCPPEController@masterlist` (the Add-Items picker) currently sources **only World 2**
  (`tbl_mr_Maininventory` + `tbl_mrcommodity` + ICS/PAR). **World 1 equipment (medical
  equipment) is not yet selectable** — wiring it in is the known next step.
- New tables: `ppe_types` → `articles` (`ppe_type_id` FK) → `rpcppe`
  (`article_id`, `category_id`, `item_id`, `issuance_type`, `issuance_id`, `emp_no`,
  `com_desc`, `u_cost`, `qty`, …). Accessed via query builder (no Eloquent models yet).

---

## 5. Quick reference — table groups

**World 1 (consumables/supply):** `ref_commoditycategory`, `ref_commoditymasterlist`,
`ref_uom`, `ref_commoditylocation`, `trans_gen_inventory`, `trans_gen_*main`/`*cart`
(RIS/PTR/ITR), `trans_rsmi*`; views `vw_scommoditylist`, `vw_geninventory`; SP
`trans_gen_inventory_IU`.

**World 2 (office supplies/property — MR):** `tbl_mrcategory`, `tbl_commodity`,
`tbl_mrcommodity`, `tbl_mr_Maininventory`, `tbl_mr_allocation`, `tbl_mrics`,
`tbl_mricslist`, `tbl_mrpar`, `tbl_mrparlist`, `tbl_mr_return*`, `tbl_mr_transfer*`;
views `vw_mrinventorylist_withoum`, `vw_mrissuedlist`, `vw_MainInventory`.

**New (this project):** `ppe_types`, `articles`, `rpcppe`, `app_settings`,
`inventory_transfer_reports`, `inventory_transfer_report_items`.
