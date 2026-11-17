from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from pathlib import Path

OUT = Path('wallet-ecosystem/docs/customer-event-demo-reference.docx')
OUT.parent.mkdir(parents=True, exist_ok=True)
doc = Document()
sec = doc.sections[0]
sec.top_margin = Inches(.65); sec.bottom_margin = Inches(.65)
sec.left_margin = Inches(.75); sec.right_margin = Inches(.75)
styles = doc.styles
styles['Normal'].font.name = 'Aptos'; styles['Normal'].font.size = Pt(10)
styles['Normal'].font.color.rgb = RGBColor(40, 50, 60)
for name, size, color in [('Title', 27, '123A4A'), ('Heading 1', 18, '123A4A'), ('Heading 2', 13, '087E83')]:
    st = styles[name]; st.font.name = 'Aptos Display'; st.font.size = Pt(size); st.font.bold = True; st.font.color.rgb = RGBColor.from_string(color)

def shade(cell, fill):
    pr = cell._tc.get_or_add_tcPr(); shd = OxmlElement('w:shd'); shd.set(qn('w:fill'), fill); pr.append(shd)

def table(headers, rows, widths=None):
    t = doc.add_table(rows=1, cols=len(headers)); t.alignment = WD_TABLE_ALIGNMENT.CENTER; t.style = 'Light Shading Accent 1'
    for i, h in enumerate(headers):
        c=t.rows[0].cells[i]; c.text=h; shade(c, '123A4A')
        for r in c.paragraphs[0].runs: r.font.bold=True; r.font.color.rgb=RGBColor(255,255,255)
    for row in rows:
        cells=t.add_row().cells
        for i, val in enumerate(row):
            cells[i].text=str(val); cells[i].vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
    if widths:
        for row in t.rows:
            for i,w in enumerate(widths): row.cells[i].width=Inches(w)
    doc.add_paragraph().paragraph_format.space_after = Pt(1)
    return t

def bullet(text): doc.add_paragraph(text, style='List Bullet')
def step(title, body):
    p=doc.add_paragraph(); p.paragraph_format.space_after=Pt(3); r=p.add_run(title+' '); r.bold=True; p.add_run(body)

title=doc.add_paragraph(style='Title'); title.add_run('Local Customer Event Demo Reference')
p=doc.add_paragraph('Five organisations, active memberships, ten published free events, and the customer app registration path')
p.runs[0].font.size=Pt(12); p.runs[0].font.color.rgb=RGBColor.from_string('087E83')
doc.add_paragraph('This guide records the local demo setup and the admin and customer screens needed to create, review, approve, browse, and register events. The included fixtures are synthetic and restricted to local/testing environments.')

doc.add_heading('What was provisioned', level=1)
table(['Organisations', 'Memberships', 'Events', 'Customer registration'], [[
    '5 active local demo organisations', 'Creator, reviewer, approver, and demo customer for each organisation (20 active memberships)',
    '2 per organisation (10 total), upcoming, published, active sales, free General Admission, capacity 100',
    'Free registration from Services > Free Event Registration; tickets appear in My Event Tickets']
], [1.3,2.0,2.1,1.8])
doc.add_paragraph('The seeded events are local customer-view fixtures. They are inserted as published so that the mobile catalogue can be validated while publication readiness remains fail-closed for normal event operations. Do not use this fixture method in production.')

doc.add_heading('Local logins', level=1)
table(['Role and app', 'Login', 'Secret'], [
    ['Admin panel administrator', 'admin@olekard.local', 'Admin123!'],
    ['Organisation and event creator', 'event.creator@olekard.local', 'LocalEventCreator2026!'],
    ['Organisation and event reviewer', 'event.reviewer@olekard.local', 'LocalEventReviewer2026!'],
    ['Organisation and event approver', 'event.approver@olekard.local', 'LocalEventApprover2026!'],
    ['Customer mobile app', '+26771000001', 'PIN 123456'],
], [2.15,2.5,2.5])
doc.add_paragraph('All credentials above are local demo credentials. The customer app must use the seeded customer account and a trusted device fingerprint. Admin users may be prompted for MFA depending on local configuration.')

doc.add_heading('Screen by screen workflow', level=1)
table(['Step', 'Screen', 'Role', 'What to do / what to verify'], [
    ['1', 'Admin login (http://localhost:3000)', 'Creator', 'Sign in as event.creator. The left navigation should show Organisation Onboarding, Organisation Memberships, and Events.'],
    ['2', 'Organisation Onboarding (/organisations/onboarding)', 'Creator', 'Choose the create/request action, enter organisation name, registry reference, and evidence reference, then submit. The request appears in the review queue.'],
    ['3', 'Organisation Onboarding review queue', 'Reviewer', 'Sign in as event.reviewer. Open the pending organisation row, inspect its details, add review comments, and send it for approval.'],
    ['4', 'Organisation Onboarding approval queue', 'Approver', 'Sign in as event.approver. Open the pending approval row, review organisation details and reviewer comments, then approve. Confirm the organisation shows Active.'],
    ['5', 'Organisation Memberships (/organisations/memberships)', 'Creator', 'Select the active organisation and request membership by user email. No actor UUID needs to be typed.'],
    ['6', 'Organisation Memberships review list', 'Reviewer', 'Find the pending membership, inspect the organisation and account, then select Activate. Active membership is required before event authoring.'],
    ['7', 'Events authoring (/community/events)', 'Creator', 'Select an organisation from the name dropdown. Organiser name fills from that selection. Enter event name, format, timezone, start/end, sales window, capacity, ticket categories, policies, and venue. Assign reviewer/approver by display name.'],
    ['8', 'Event review and approval', 'Reviewer then approver', 'Review the submitted event and comments; approver checks price and event details. For this local customer demo use the repeatable seeder command below to provision published customer-visible events.'],
    ['9', 'Customer app Services screen', 'Customer', 'Sign in with the phone/PIN above, open Services, and choose Free Event Registration. The card now opens the event catalogue.'],
    ['10', 'Events catalogue and registration', 'Customer', 'Browse the ten events by event title and organisation. Choose Register for a General Admission free ticket. Registration returns to My Event Tickets.'],
    ['11', 'My Event Tickets (/events/tickets)', 'Customer', 'Confirm the ticket shows the selected event and valid ticket status. Use the ticket details screen to inspect the QR/entry credential.'],
], [.45,2.0,1.0,4.0])

doc.add_heading('Create or refresh the repeatable demo data', level=1)
doc.add_paragraph('Run these commands from PowerShell. Seed the base workflow users and customer first if they do not already exist:')
for line in [
    'cd C:\\Projects\\OleKardEcosystem\\wallet-ecosystem\\services\\api',
    'php artisan db:seed --class=DevEventWorkflowUsersSeeder',
    'php artisan db:seed --class=DevCustomerAppSeeder',
    'php artisan db:seed --class=DevFiveOrganisationEventsSeeder',
]:
    p=doc.add_paragraph(); p.paragraph_format.left_indent=Inches(.25); r=p.add_run(line); r.font.name='Consolas'; r.font.size=Pt(9); r.font.color.rgb=RGBColor.from_string('123A4A')
doc.add_paragraph('The last command is idempotent: it updates the same five local organisations, memberships, events, and categories by stable IDs. It refuses to run unless APP_ENV is local or testing. It creates no ticket registrations; each customer can register from the app.')

doc.add_heading('Run the customer app locally', level=1)
step('API:', 'start the Laravel API on http://127.0.0.1:8000 with PostgreSQL available and the latest API migrations applied.')
step('Customer mobile:', 'from apps/customer run flutter pub get, then flutter run -d <emulator-id>. For an APK, run flutter build apk --debug and install with the full Android SDK platform-tools adb.exe path if adb is not on PATH.')
step('Web customer:', 'the customer Flutter web build should be started from apps/customer with flutter run -d chrome or flutter run -d web-server --web-port 50416. The API must allow the app origin through the local CORS configuration.')
step('Refresh:', 'reopen Services or pull down to refresh in the Events catalogue. Upcoming published events with active free allocations are listed.')

doc.add_heading('Event field and validation reference', level=1)
table(['Field', 'Rule used by the form / fixture'], [
    ['Organisation', 'Choose by display name. The organiser name is populated automatically.'],
    ['Event dates', 'Event start must be in the future and before event end.'],
    ['Sales dates', 'Sales start must be before sales end; sales end must be before event start.'],
    ['Capacity', 'Total category capacities cannot exceed event capacity. Demo events use 100 / 100.'],
    ['Free ticket', 'Price is zero; each attendee is limited to 2 in these fixtures.'],
    ['Timezone and venue', 'Africa/Gaborone and BW for all seeded records.'],
], [1.5,5.8])

doc.add_heading('Troubleshooting', level=1)
bullet('No events appear: confirm the API is reachable, customer authentication succeeded, and the fixtures were seeded after migrations.')
bullet('403 or login failure: confirm the customer is using +26771000001 with PIN 123456 and the mobile app device fingerprint is the seeded mobile-customer-+26771000001 value.')
bullet('Registration fails: confirm event sales are currently active, the category is free, and the customer has not exceeded the per-person allowance.')
bullet('Event authoring says active organisation-scoped Events assignment required: membership and role permissions alone are not enough; an organisation-scoped Events create assignment must also be present for that user.')

doc.add_heading('Implementation files', level=1)
for path in [
    'services/api/database/seeders/DevFiveOrganisationEventsSeeder.php',
    'services/api/app/Modules/Events/Infrastructure/Http/Controllers/CustomerEventCatalogueController.php',
    'services/api/routes/modules/events.php',
    'apps/customer/lib/modules/events/presentation/screens/customer_event_catalogue_screen.dart',
    'apps/customer/lib/modules/services/presentation/screens/services_home_screen.dart',
    'apps/customer/lib/core/router/app_router.dart',
]: bullet(path)

doc.save(OUT)
print(OUT.resolve())
