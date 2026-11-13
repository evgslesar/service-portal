# Service Portal: Client and Technician Interaction Scenarios

**Version:** 1.0  
**Status:** normative UX scenarios for the revised development plan, version 2.1. These describe the intended application, not implemented or tested functionality.  
**Audience:** clients and service technicians without IT experience.  
**Scope:** client contact/representative and technician/lead journeys, including normal operations, interruptions, and recovery. No application implementation or external account setup is authorized by this document.

## 1. Product principle

A client wants to report a problem, know when someone will arrive, understand costs, and confirm whether the problem was fixed.

A technician wants to see the next job, find the location/contact, do the work, attach evidence, and move on.

Neither needs to understand database records, workflow enums, billing groups, operation keys, or internal organizational structures.

### Nonnegotiable interaction rules

1. A task page answers: what is happening, what I need to do, and what happens next.
2. One primary action per task step. Optional details do not obscure it.
3. Known information is reused rather than requested again.
4. No manual selection of technical statuses, contracts, package IDs, warehouse codes, or payment allocations in these roles' ordinary workflow.
5. A waiting state names the responsible party; it does not look like a user error.
6. Every unsaved/uploading/failed state is visible. Never display success before the server confirms it.
7. Approval, payment, acceptance, and actual-use confirmation are explicit decisions. Convenience never implies hidden consent.
8. Phone use is the default for technicians; all essential interactions work at 360 CSS pixels with touch targets at least 44 by 44 pixels.
9. Help is one tap away. Clients contact the service company; technicians contact the dispatcher.
10. The application is online-only. A briefly disconnected open form preserves unsent input, but does not secretly queue work transitions or financial actions.

Quoted Russian text below specifies proposed product interface copy. Internal enum names remain out of the user interface.

## 2. Role boundaries and navigation

| Person | Everyday navigation | What is deliberately absent |
|---|---|---|
| Client contact | Home, Requests, Sites, Help/Profile | Other sites, estimates, amounts, invoices, acceptance/approval buttons, financial administration |
| Client representative | Same navigation, plus relevant decision/payment cards | Contract-mapping configuration, billing-mode choices, accounting/stock tools |
| Technician | Today, History, Help/Profile | Organization-wide request lists, finance, purchasing, warehouses, technical workflow administration |
| Lead technician | Technician navigation plus a team-result task in Today | Re-entering teammates' reports, manually building package/ledger structures |

Combined staff roles do not merge all specialist menus into Today. A user with warehouse/finance permissions switches deliberately to that separate workspace.

Technical work packages exist in the system, but client-facing sections use understandable scope names such as electrical repair or plumbing repair.

## 3. Client scenario: report, follow, decide, and finish

### C01. First entry

**Situation:** an invited user opens the portal on a phone or computer.

| Step | Person does | Application does |
|---|---|---|
| 1 | Opens the invitation | Shows the company name, purpose, and a short password-setup form; no organization/site registration |
| 2 | Sets a password | Uses the preconfigured account, organization, and site permissions |
| 3 | If an approver, completes guided authenticator setup | Explains the code in ordinary language; offers staff-assisted setup and recovery instructions |
| 4 | Continues | Opens Home, or the original permitted notification task if entry began from a deep link |

- Ordinary client contacts are not forced to enroll in optional two-factor authentication.
- Mandatory two-factor authentication is not repeated for every photo, acceptance, or ordinary action during a valid session.
- Telegram connection is optional and offered later; refusing it does not block reporting a problem.
- A lost invitation/password/authenticator has a visible recovery/support route, not a dead end.

**Success:** the person reaches the correct workspace without creating directories or understanding roles.

### C02. Submit a new problem

**Situation:** a client notices a leaking pipe at an office.

**Entry:** prominent `Сообщить о проблеме` action on Home and on the relevant site page.

#### One short form

| Field | Behavior |
|---|---|
| `Объект` | Only permitted active sites; preselected and visibly named when one eligible site exists |
| `Что случилось?` | The only mandatory text field; ordinary description, not a technical classification |
| `Где именно?` | Optional free place note or convenient existing zone; no mandatory zone-directory search |
| `Добавить фото` | Optional camera/gallery selection with previews; the client does not choose format or compression |

There is no mandatory separate title, category, priority, contract, work-package, or billing-mode field.

**Example description:** a short plain sentence identifying the leak and room is sufficient. The system derives an initial title from the first description line without AI; staff classify the request later.

**Primary action:** `Отправить заявку`.

#### After sending

- Show `Заявка отправлена` with the request number, site, and a short next-step explanation.
- The client is not asked to assign a technician, select a status, or calculate response time.
- Submitted text remains visible in the request card. Later clarifications are messages, not edits to the original statement.
- Repeated taps/retries cannot create another request.
- Selected photos show progress. The client may retry/remove a failed optional photo or explicitly send without it; the application never silently drops a selected attachment.
- At intake the server checks site access and that the site has valid service coverage. Staff resolve category/contract ambiguity before authorizing work.
- If coverage/access changed, preserve the entered description and show a plain explanation plus support contact. Do not expose another organization's information.

**Success:** a problem is reported in one form, with no prerequisite understanding of service contracts.

**Target:** at most 90 seconds excluding login and taking photographs.

### C03. Understand what happens next

**Entry:** request card or a notification link.

The top of the page contains only the next relevant action, planned attendance, and readable progress.

| Situation | Main message | Client action |
|---|---|---|
| Request received | `Заявка получена. Планируем выезд.` | No action; add clarification if useful |
| Visit scheduled | `Мастер приедет ...` with actual date/time, site, and specialist name | Read appointment or request a change |
| Staff need information | Short specific question | Reply in the same request |
| Work underway | `Работы выполняются` | No action unless asked |
| Representative must decide | `Нужно ваше решение` for the authorized person | Open the exact decision |
| Ordinary contact has no decision authority | `Следующий шаг — за ответственным вашей организации` | No unavailable buttons or financial amounts |

- Appointment time is site-local. Show a readable date/time and timezone; never UTC codes.
- Parallel visits are grouped by date where useful, but actual separate times remain visible. Do not invent one continuous interval when specialists have gaps between visits.
- The user need not open a package tree to find the appointment.
- Request history, work details, and documents expand below the main card.
- Public replies have a simple message box and optional attachments. There is no client-facing internal-note switch.

#### If the appointment is unsuitable

1. Select `Время не подходит` on that appointment.
2. Enter a short preference/comment and send.
3. See `Передали диспетчеру. Текущее время пока не изменилось.`

This is a message to the dispatcher, not direct scheduling authority. A confirmed change produces an updated appointment and notification.

**Success:** the client always understands whether action is required and who has the next step.

### C04. Review diagnosis and additional costs

**Actor:** client representative with approval/acceptance rights.

**Situation:** diagnostics are complete and repairs outside the recurring service fee have been estimated.

**Entry:** one notification linking directly to the request's review section.

#### The review page shows, in this order

1. What the technicians found, in understandable terms, with optional photos.
2. What work is proposed and what it will fix.
3. Maximum approved cost, including the configured tax treatment.
4. Advance due now: 50% of that approved estimate.
5. Later payment: actual accepted work minus advance; charges cannot exceed approved scope/total without another approval.
6. Optional line details and document downloads.

**Example:** maximum 20,000 RUB; advance 10,000 RUB; the later balance depends on actual accepted usage and may be less than 10,000 RUB. These figures are an example, not a fixed product tariff.

#### Related decisions without repeated navigation

- If both diagnostic results and their associated estimate are ready, offer `Подтвердить диагностику и смету`.
- Immediately above the action, explicitly list which diagnostic results and which estimate version are being confirmed.
- One composite confirmation records the diagnostic acceptance and estimate approval separately and atomically.
- `Есть замечания к диагностике` and estimate-only approval remain independently available. Accepting diagnosis never silently approves costs, and approving costs never silently accepts diagnosis unless the user chose the explicit combined action.
- If a displayed result/estimate changed, do not commit half the decisions. Retain input, show what changed, and ask for a fresh explicit confirmation on the updated page.
- Payment remains a separate action. Approving the estimate does not debit money.

#### If the estimate is unsuitable

Choose `Обсудить стоимость`, enter the concern, and send a recorded response. Additional scope remains unapproved; staff revise or cancel it. A revised estimate prominently shows old/new totals and the reason for change.

**Success:** the user understands what is being authorized, what is payable today, and why a later amount may differ.

### C05. Pay an advance or an issued balance

**Actor:** client representative. Ordinary contacts do not see financial actions/amounts.

**Entry:** `Оплатить ... ₽` on the exact invoice/request payment task.

#### Card or SBP

1. See the correct company, request/invoice basis, and amount; do not type an amount.
2. Select the primary payment action and continue to YooKassa's hosted form.
3. Choose card or SBP on the provider side and complete that interaction.
4. Return to the portal's payment-status page.

- Show `Проверяем оплату` until server-side confirmation arrives.
- Browser return alone never produces a paid status or opens a work gate.
- Pending/unknown results say that payment is being checked and that the user should not pay again yet. There is a support action and a safe status refresh.
- The user never reconciles provider transactions, retries API calls, allocates advances, or handles operation keys.
- A confirmed payment updates the relevant task; other unpaid invoices are not incorrectly marked settled.

#### Ordinary bank transfer

1. Download/open the ready invoice/company payment details.
2. Arrange the transfer outside the portal.
3. Optionally select `Сообщить об оплате` to notify finance.

The portal says that finance will verify the transfer. This message is not payment proof and never changes confirmed balance or repair readiness.

**Success:** the client can pay with a familiar method and distinguish confirmed payment from verification in progress.

### C06. Review completed work

**Actor:** client representative.

**Entry:** `Проверить результат` task linked to the exact submitted evidence.

#### Result page

- What was completed and where.
- Photos/evidence and a short result summary.
- Actual accepted-work amount, previously paid advance, and remaining payable/refundable amount where applicable.
- Optional technical/checklist details and work-report download.
- Two clear actions: `Принять работы` and `Есть проблема`.

The person does not choose `closed`, create an invoice, or decide how deposits are allocated.

#### Several completed scopes

- Show readable cards such as electrical repair and plumbing repair, not work-package IDs.
- Multiple visible ready results can be confirmed with `Принять перечисленные работы`, with the exact scope clearly displayed.
- Each scope retains its own acceptance record. The server rejects a stale composite confirmation atomically.
- Unfinished scopes remain visible as still underway; accepting ready scopes does not claim the whole request is complete.
- Rejecting a specific result applies only to that selected result, not automatically to all parallel work.

#### If something is wrong

1. Select `Есть проблема` on the relevant result.
2. Describe the issue in ordinary language; add a photo if useful.
3. Send and see `Замечание отправлено. Свяжемся по следующему шагу.`

The client does not classify defect versus new scope. Staff assess responsibility, but cannot force acceptance. New charges require a new explicit approval.

**Success:** evidence and money are understandable; acceptance is deliberate and tied to the reviewed result.

### C07. Finish and find records later

- If all results are accepted but money remains due, show `Работы приняты. Осталось оплатить ... ₽` to the representative, with the exact payment task.
- If a refund is being arranged, show the actual stage; never say money was refunded before confirmed success.
- Ordinary contacts see operational progress without confidential financial amounts.
- After all required acceptance and request-related settlement, show `Заявка завершена` and move it to history.
- History remains searchable by request number/site; accessible messages/results and authorized documents remain available.
- A new fault is reported through `Сообщить о новой проблеме`, creating a new linked request rather than reopening the old one.

**Success:** completed work, remaining payment, and a fully completed request are not confused.

### C08. Planned maintenance

1. Receive an actual scheduled-maintenance notice or see the appointment in the request list.
2. Check access/time; request a change if needed.
3. Review and accept the maintenance result when submitted.

The client does not create recurring requests, configure schedules, or pay a repair deposit for covered maintenance. Recurring contract invoices are separate representative tasks.

### C09. Client exceptions

| Exception | Required recovery |
|---|---|
| No permission to approve/accept | State who has the next step; hide unavailable controls and financial data, rather than let the person hit a permission error |
| No permitted sites/eligible coverage | Explain that setup/coverage must be checked; show the service contact; do not request directory administration |
| Wrong/stale notification link | Return to permitted Home with a safe explanation; disclose no inaccessible record name/content |
| Photo upload fails | Retry/remove that photo, preserve text and successfully uploaded photos |
| Connection drops while typing | Keep unsent input in the open page, show Not saved, retry intended drafts after reconnection; do not promise offline submission |
| Approval/payment result is uncertain | Read server status; no blind repeat confirmation or new payment |
| Estimate/result changed | Show the changed decision; preserve the user's comment; require fresh consent rather than overwrite |
| Urgent safety issue | Prominent company contact and a clear notice that the portal is not an emergency-response channel |

## 4. Technician scenario: arrive, work, report, and move on

### T01. Start the day

**Entry:** Today after login, or the assigned-job notification link.

A job card shows time, site/address, a short task description, and whether anything prevents work from starting.

- Nearest/current job is first; later appointments follow.
- Overdue appointment timing is understandable, not a database status.
- A blocked job says who resolves it. The technician is not prompted to collect payment or approve stock overrides.
- A lead's ready team-result task appears separately in Today.
- History is secondary; finance dashboards do not appear by default even for staff with combined roles.

**Primary action:** `Открыть задание`.

**Success:** the technician identifies the next visit without searching organization-wide requests.

### T02. Arrive and start

The job page puts time, address, access instructions, site contact, and work scope first.

- Phone numbers have tap-to-call behavior; addresses/instructions can be copied.
- Site/request identity is already known; no manual re-entry.
- No location tracking, route planning, or travel-time calculation is required.

**Primary action on arrival:** `Начать работу`.

The server checks assignment, active account, schedule/execution conflict, approval, required advance, and allowed material readiness. If valid, factual start time is recorded.

If blocked, show a short reason and `Связаться с диспетчером`; retain the job. Do not expose approval/stock overrides to the technician.

**Success:** work has a confirmed start without technical status selection.

### T03. Document work while doing it

Use one task page with these sections:

1. Relevant checklist; mandatory evidence is clearly identified.
2. `Добавить фото` from camera/gallery with previews and progress.
3. `Что сделано?` short work-result text.
4. `Использованные материалы` from the relevant permitted issued/held list, with names/units and actual quantities.
5. Worked-time suggestion to confirm before completion.

- Reuse site, scope, technician, previous own draft/evidence, and issued materials.
- Do not ask for contract IDs, prices, tax treatment, supplier cost, warehouses, or ledger documents.
- Actual-used quantities start at zero. Planned quantity is not silently reported as consumed; the technician confirms actual use or no use.
- Piece quantities use convenient controls; fractional units use an appropriate numeric keyboard with the unit always visible.
- Checklist confirmations remain real confirmations. No mandatory safety point is prechecked solely to save clicks.
- Save drafts on a short debounce/field blur, with Saving/Saved/Not saved states. Successful draft saves do not post duplicate stock consumption.
- Report text can be one informative sentence; lengthy boilerplate is not required. Prewritten examples are hints, not automatically submitted evidence.

**Photo handling:** supported Android/iPhone camera/gallery files normalize automatically. Original photo limits are 20 MB and 40 megapixels; normalized permanent files at most 10 MB; maximum five selected files per submission. Users never manually choose JPEG/HEIC or resize a supported image. Unsupported/unsafe content gets a specific correction.

**Success:** evidence accumulates alongside the work rather than through a second administrative session.

### T04. Finish the visit

**Entry:** `Завершить выезд` opens the completion section of the same job page.

1. See any missing mandatory checklist/result/photo item and go directly to it.
2. Confirm/correct suggested worked time; elapsed time is only a suggestion, not an automatically billed quantity.
3. Confirm actual-used material quantities.
4. Review the short report and select the final explicit action.

#### Ordinary technician

**Action:** `Отправить отчёт`.

- The server finalizes factual end, report/evidence, and authorized actual-consumption postings once.
- Show `Отчёт передан. Можно перейти к следующему заданию.`
- The completed visit moves to History; lead acceptance preparation and client payment are someone else's tasks.

#### Sole technician who is also lead

- If every required package condition is satisfied, offer `Завершить и передать результат`.
- One authorized transaction finishes the visit and submits the package snapshot; it does not bypass required evidence, cost caps, or readiness.
- If another required contribution/visit remains, offer ordinary report completion instead, then the separate lead task when ready.

Do not require another Save, modal confirmation, and separate status change after a clear final review/commit.

**Success:** reporting is complete; retries cannot duplicate report/stock/result effects.

**Target:** at most 60 seconds when evidence has already been recorded, excluding actual photo/checklist collection.

### T05. Lead reviews the team's result

**Entry:** a task in Today, not a package-administration screen.

The application assembles completed required contributions automatically and shows:

- What work was completed.
- Relevant reports/photos/checklist evidence.
- Which teammate's contribution is missing, if any.
- A short editable lead summary; original teammate reports are immutable.
- Readiness for submission in plain language.

The lead does not retype reports, copy photos, select accounting postings, or calculate the invoice.

**Primary action when ready:** `Передать результат клиенту`.

- Required active/missing work blocks submission with a specific next step.
- Actual usage outside approved scope is escalated to the dispatcher/finance. The lead cannot silently change approved rates or charges.
- Submission preserves separate client acceptance and financial processing.
- After submission, there is no payment-collection task in the lead's technician workspace.

**Success:** team coordination is a review decision, not repeated data entry.

### T06. Cannot continue

**Entry:** persistent `Нужна помощь` action on the job.

Offer short reasons: `Нет доступа`, `Не хватает материалов`, or `Другая причина`. Other requires a short explanation; preset reasons already supply a meaningful reason, with optional detail/photo.

#### Still able to continue approved work

Send a help message without falsely ending the visit. The page says that the dispatcher has been notified.

#### Work must stop

Select `Не могу продолжить` after the reason:

- Save actual work/evidence and confirmed actual use so far.
- End this visit as interrupted and notify the dispatcher.
- Tell the technician that staff will arrange the next step; do not ask them to select `on_hold`, create another visit, assign themselves, or buy missing materials.
- Other technicians' parallel visits are unaffected.
- A brief offline period cannot confirm this transition; show the unsent state and contact fallback until the server records it.

**Success:** the interruption is described once and reaches the person who can resolve it.

### T07. Additional materials or work

- Use the same help action to explain what is missing or what new issue was found.
- The technician does not pick an unapproved substitute price, create an estimate, choose a warehouse, or authorize extra charges.
- Dispatcher/warehouse/finance handle availability, partial-work authorization, estimate revision, and any additional advance.
- Updated allowed scope/material readiness appears on the job once confirmed.
- Existing approved work can continue only within the unchanged permitted scope; no negative stock or unauthorized consumption.

**Success:** new information reaches staff without making the technician an accountant or purchaser.

### T08. Rework

1. Receive a newly assigned visit with the specific client concern and staff-approved scope.
2. See the previous relevant evidence; old reports stay immutable.
3. Start, work, document, and submit through the same familiar flow.

The technician does not decide defect liability or client charges. Company-funded rework is tagged internally; new chargeable scope has the necessary approval/payment readiness before start.

**Success:** rework uses the same interface as an ordinary job and preserves prior evidence.

### T09. Technician exceptions

| Exception | Required recovery |
|---|---|
| Another visit is still executing | Show which permitted task needs completion/interruption and link to it; never offer a technical override |
| Camera permission denied | Offer gallery/file selection and platform guidance without losing the report |
| Large/unsupported photo | Explain the actual limit/correction; preserve other evidence and draft text |
| Required evidence is missing | List only missing items, with direct navigation; do not show a generic validation failure |
| Connection drops | Visible unsaved/uploading states; preserve open-page edits; no claim that Start/Finish was recorded offline |
| Finish result is uncertain | Recheck the existing operation status before retrying the same intent; never duplicate consumption/report |
| Material quantity fails validation | Point to the item/quantity and contact dispatcher/warehouse; do not expose database errors |
| Assignment changed | Show a safe update/Today route; preserve access restrictions and do not reveal inaccessible work |
| Client has not paid after submission | No technician task; ordinary work is already in History |
| Lost authenticator | Controlled support/reset route; no bypass of mandatory authentication |

## 5. Cross-role handoff: who performs the hidden work

| Trigger | Client/technician sees | Responsible internal role |
|---|---|---|
| New problem | Request received | Dispatcher: category, priority, contract, packages, lead |
| Diagnostic report and estimate ready | Findings plus explicit confirmation/cost summary | Lead/finance prepare immutable evidence/estimate; client representative decides |
| Appointment change requested | Pending staff confirmation, old appointment unchanged | Dispatcher |
| Bank transfer reported | Verification pending | Finance confirms receipt/allocations |
| Extra materials requested | Help sent / readiness update | Warehouse and dispatcher; finance if approved cost changes |
| Technician report finished | Report delivered | Lead prepares/submits final scope; server posts validated ledger |
| Work accepted | Accepted work and remaining payment if authorized | Server/finance: actual invoice, advance offsets, settlement |
| Defect reported | Concern sent, next contact expected | Dispatcher assesses; client retains acceptance authority |
| Refund needed | Actual refund progress | Finance approves; provider/manual process confirms |

Hidden administration does not mean hidden decisions. Client approval, actual-use confirmation, lead submission, and financial confirmation remain explicit to their authorized actor.

## 6. UX checks before pilot acceptance

Use five non-IT participants per role with fictional data and no step-by-step moderator assistance. Record task completion, time, wrong turns, and misunderstanding rather than ask whether the screen looks attractive.

| Check | Passing target |
|---|---|
| Report a problem at the correct site | At least four of five clients succeed within 90 seconds excluding login/photo capture |
| Find the next appointment and ask for a time change | At least four of five clients succeed; nobody believes the old appointment changed before confirmation |
| Explain maximum cost, advance, and later actual balance | Every approving participant distinguishes all three correctly |
| Approve a displayed estimate/diagnosis without unintended consent | At least four of five complete unassisted; zero hidden/wrong-result decisions |
| Review/accept or report a result issue | At least four of five complete within 60 seconds excluding lengthy evidence review |
| Find the next assigned job | At least four of five technicians succeed without opening staff administration |
| Finish an already-documented visit | At least four of five finish within 60 seconds; nobody needs warehouse/finance knowledge |
| Pause/help and understand the next owner | At least four of five technicians succeed; no other parallel visit is stopped accidentally |
| Lead reviews team evidence | No repeated typing/copying of colleagues' reports; no submission with missing required work |
| Network/photo/validation recovery | Existing input retained in the open form, specific correction visible, no false Saved/Finished/Paid |
| Mobile accessibility | Essential actions usable at 360 px, touch targets at least 44 px, keyboard/focus/status checks pass |

These are target checks for later prototypes/application tests, not claims of tests already run.

## 7. Required plan amendments

1. Minimal client intake: site plus description; optional location/photo; nullable category/intake contract until dispatcher triage.
2. Internal contract/billing-mode selection belongs to dispatcher/finance, not clients or ordinary technicians.
3. Client-facing next-action/result cards replace package trees and enum codes; specialist workbenches remain available to authorized staff.
4. Explicit related diagnostic/estimate confirmations and listed-result acceptance may be atomic composite actions; no invisible batch decisions or payment.
5. Actual-use/time entry is short and confirmed; ledger posting/retry handling happens behind the form without weakening stock/cost checks.
6. Automatically prepared lead result and eligible sole lead-worker finish/submit avoid duplicate reporting.
7. Technician task completion is independent of later client settlement; request closure still requires the agreed acceptance/settlement predicate.
8. Supported phone photos normalize automatically, including bounded HEIC/HEIF handling; file/security limits remain enforced.
9. Appointment-change, help, and payment-review messages have safe typed context but never grant schedule/payment authority.
10. Guided onboarding, notification deep-link restoration, visible autosave, focused recovery, simple Help, and measurable non-IT usability checks are part of acceptance.

The source version 1.0 specification is not rewritten by this scenario document. The revised plan identifies the corresponding data/action/test changes; no application implementation is claimed.