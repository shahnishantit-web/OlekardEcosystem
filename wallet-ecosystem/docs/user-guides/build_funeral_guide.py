from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.enum.section import WD_SECTION
from pathlib import Path

OUT = Path(__file__).with_name('funeral-assistance-user-guide.docx')
doc = Document()
sec = doc.sections[0]
sec.top_margin = Inches(.72); sec.bottom_margin = Inches(.7)
sec.left_margin = Inches(.78); sec.right_margin = Inches(.78)

styles = doc.styles
styles['Normal'].font.name = 'Aptos'; styles['Normal'].font.size = Pt(10.5); styles['Normal'].font.color.rgb = RGBColor(35, 48, 62)
styles['Normal'].paragraph_format.space_after = Pt(6)
for name, size, color in [('Title', 28, '17365D'), ('Heading 1', 19, '17365D'), ('Heading 2', 13, '146C78'), ('Heading 3', 11, '17365D')]:
    st = styles[name]; st.font.name = 'Aptos Display'; st.font.size = Pt(size); st.font.bold = True; st.font.color.rgb = RGBColor.from_string(color)
    st.paragraph_format.space_before = Pt(10 if name != 'Title' else 0); st.paragraph_format.space_after = Pt(5)

def shade(cell, fill):
    tcPr = cell._tc.get_or_add_tcPr(); shd = OxmlElement('w:shd'); shd.set(qn('w:fill'), fill); tcPr.append(shd)
def cell_margins(cell, top=100, start=110, bottom=100, end=110):
    tc = cell._tc; tcPr = tc.get_or_add_tcPr(); mar = OxmlElement('w:tcMar')
    for m, v in [('top',top),('start',start),('bottom',bottom),('end',end)]:
        node=OxmlElement('w:'+m); node.set(qn('w:w'),str(v)); node.set(qn('w:type'),'dxa'); mar.append(node)
    tcPr.append(mar)
def set_borders(table):
    tblPr=table._tbl.tblPr; borders=OxmlElement('w:tblBorders')
    for edge in ('top','left','bottom','right','insideH','insideV'):
        e=OxmlElement('w:'+edge); e.set(qn('w:val'),'single'); e.set(qn('w:sz'),'4'); e.set(qn('w:color'),'D5DEE8'); borders.append(e)
    tblPr.append(borders)
def table(headers, rows, widths=None):
    t=doc.add_table(rows=1, cols=len(headers)); t.alignment=WD_TABLE_ALIGNMENT.CENTER; t.autofit=False
    if widths:
        for row in t.rows:
            for i,w in enumerate(widths): row.cells[i].width=Inches(w)
    for i,h in enumerate(headers):
        c=t.rows[0].cells[i]; c.text=h; shade(c,'17365D'); c.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER; cell_margins(c,130,120,130,120)
        for p in c.paragraphs:
            for r in p.runs: r.font.bold=True; r.font.color.rgb=RGBColor(255,255,255); r.font.size=Pt(9.5)
    for ri,row in enumerate(rows):
        cells=t.add_row().cells
        for i,val in enumerate(row):
            cells[i].text=str(val); cells[i].vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER; cell_margins(cells[i])
            if ri%2==1: shade(cells[i],'F2F6FA')
            for p in cells[i].paragraphs:
                p.paragraph_format.space_after=Pt(1)
                for r in p.runs: r.font.size=Pt(9.3)
        if widths:
            for i,w in enumerate(widths): cells[i].width=Inches(w)
    set_borders(t); doc.add_paragraph().paragraph_format.space_after=Pt(1)
    return t
def para(text='', boldlead=None):
    p=doc.add_paragraph()
    if boldlead and text.startswith(boldlead): p.add_run(boldlead).bold=True; p.add_run(text[len(boldlead):])
    else: p.add_run(text)
    return p
def bullets(items):
    for item in items:
        p=doc.add_paragraph(style='List Bullet'); p.paragraph_format.space_after=Pt(3); p.add_run(item)
def steps(items):
    for title, body in items:
        p=doc.add_paragraph(style='List Number'); p.paragraph_format.space_after=Pt(6)
        p.add_run(title+' ').bold=True; p.add_run(body)
def page(): doc.add_page_break()

# Header/footer
header=sec.header.paragraphs[0]; header.text='OLEKARD  /  COMMUNITY SERVICES'; header.alignment=WD_ALIGN_PARAGRAPH.RIGHT
for r in header.runs: r.font.name='Aptos'; r.font.size=Pt(8); r.font.bold=True; r.font.color.rgb=RGBColor(20,108,120)
foot=sec.footer.paragraphs[0]; foot.alignment=WD_ALIGN_PARAGRAPH.CENTER
run=foot.add_run('Funeral Assistance User Guide  •  Local demo workflow'); run.font.size=Pt(8); run.font.color.rgb=RGBColor(100,112,124)

# Cover
p=doc.add_paragraph(); p.paragraph_format.space_before=Pt(48)
p=doc.add_paragraph(style='Title'); p.add_run('Funeral Assistance User Guide')
p=doc.add_paragraph(); p.paragraph_format.space_after=Pt(18)
r=p.add_run('A step-by-step guide for community setup, household contributions, reporting and beneficiary payout'); r.font.size=Pt(13); r.font.color.rgb=RGBColor(20,108,120)
para('This guide explains the full Segotsamollo-style funeral assistance workflow in OleKard, from enabling the service to recording a contribution and reconciling support for the bereaved household. It describes the local demo setup and labels the mock payment behavior so it is not mistaken for a real bank transfer.')
doc.add_heading('Example used throughout', level=2)
table(['Example detail','Value'],[
 ['Organisation','Patel funeral Community'],
 ['Community','Patel Funeral Support Community'],
 ['Bereaved household','HOUSEHOLD-PATEL-001'],
 ['Contribution','BWP 20.00 per eligible household'],
 ['Sample collection purpose','Funeral assistance for the Patel household'],
 ['Sample group size','5 eligible households; 5 contributions would total BWP 100.00 gross'],
], [2.0,4.8])
para('The figures are illustrative. Actual totals depend on household eligibility, successful payments, refunds, fees and collection timing.')
para('Scope: Funeral Assistance is a community service. It is separate from event creation and ticketing. Organisation onboarding and organisation memberships are shared platform functions; after that, funeral communities, households, collections and payout are managed in the Funeral Assistance workflow.')
doc.add_heading('At a glance', level=2)
para('Enable the local readiness controls → create a community → register and review households → verify household representatives → configure a verified organisation payout destination → create, approve and activate a collection → households contribute in the customer app → close and reconcile → request payout → independent reviewer approves and records settlement.')

page(); doc.add_heading('1. Users and responsibilities', level=1)
para('The workflow separates setup, review and payment actions. A user should only perform actions granted to their account and assigned to the relevant organisation or community. The collection maker and payout approver must be different people.')
table(['Role','Typical user','What they do'],[
 ['Platform configuration approver','Admin user with configuration policy approval rights','Turns on the community funeral readiness policy and ensures local payment configuration is ready.'],
 ['Community manager / creator','event.creator@olekard.local (local demo role)','Creates the community, adds household references and representatives, prepares the collection and requests payout.'],
 ['Community reviewer','event.reviewer@olekard.local (local demo role)','Independently reviews household eligibility and representatives, approves and activates collections, reconciles reports, and can approve or reject a payout if granted the payout approval permission.'],
 ['Configuration approver role','event.approver@olekard.local (local demo account)','Approves configuration changes when assigned that admin configuration authority. This account is not automatically the funeral collection reviewer or payout approver.'],
 ['Household representative / customer','A verified customer account linked to a participating household','Receives the request, checks the collection details and contributes through the customer app.'],
], [1.45,2.05,3.3])
doc.add_heading('Local demo sign-ins', level=2)
table(['Account','Local demo credential'],[
 ['Creator','event.creator@olekard.local  /  LocalEventCreator2026!'],
 ['Reviewer','event.reviewer@olekard.local  /  LocalEventReviewer2026!'],
 ['Approver','event.approver@olekard.local  /  LocalEventApprover2026!'],
 ['Customer example','Use a customer account linked to a verified representative; the current demo may include +26771000014 with PIN 123456.'],
], [2.1,4.7])
para('These are local demo credentials only. Do not reuse them outside the local development environment. After permission seeders or role changes, sign out and sign back in so the session receives updated permission claims.')

doc.add_heading('2. Before starting', level=1)
steps([
 ('Start the local services.', 'Run the API and customer/admin web apps against the local database. PostgreSQL is expected on port 5432 in this workspace. Redis is not required for the described page unless the local API configuration enables a Redis-backed feature.'),
 ('Enable Funeral Assistance.', 'In Admin, open Configuration → Operational Policy → community_funeral.http_enabled, turn it on, and apply the change. If the setting is controlled by approval, submit it and have the authorized configuration approver approve it.'),
 ('Enable local mock payout ledger support.', 'For local demonstration only, set PAYMENT_MOCK_BANK_LOCAL_FUNERAL_LEDGER_ENABLED=true in the API environment, then clear cached configuration with php artisan config:clear and restart the API.'),
 ('Confirm provider setup for contributions.', 'For the funeral_contribution context and the organisation, enable an eligible mock bank or card provider account. Without an eligible provider account, checkout will report that no payment method is available.'),
 ('Confirm organisation and membership readiness.', 'The organisation must exist and be active. Community staff and households must be scoped to that organisation; household representatives must be verified before activation.'),
])
para('A readiness message in Admin means one or more prerequisites are still closed. The page may show the workflow, but setup or payment actions remain unavailable until the readiness gate is open.')

page(); doc.add_heading('3. Create the community and prepare households', level=1)
doc.add_heading('Step 1 — Create the community', level=2)
para('Sign in as the community creator and open Admin → Community → Funeral Assistance. In Community setup, choose the organisation “Patel funeral Community”, enter “Patel Funeral Support Community”, and create it. The community is a funeral-assistance group and is not an event.')
para('Assign community staff in the community’s staff section. Add the creator as manager and the reviewer as reviewer. Use named people from the available user selection where possible; UUIDs are internal identifiers and should not be typed by an operator.')
doc.add_heading('Step 2 — Add household references', level=2)
para('In Households & representatives, add each family using a private reference rather than exposing full personal details. For example, create HOUSEHOLD-PATEL-001 for the bereaved household and HOUSEHOLD-001 through HOUSEHOLD-005 for participating families. A private reference should be meaningful to authorized community staff but should not contain unnecessary sensitive details.')
doc.add_heading('Step 3 — Review eligibility', level=2)
para('The reviewer selects each pending household and marks it eligible or exempt. An exemption should have an explanation or evidence reference. Eligible households are included in a collection’s contribution snapshot; exempt households are recorded but are not charged the P20 obligation.')
doc.add_heading('Step 4 — Add and verify representatives', level=2)
para('For every eligible household, link a customer actor as its representative and enter the required evidence reference. A separate reviewer verifies the representative. The service blocks activation if any eligible participating household lacks a verified representative. The verified representative is the customer who sees and pays that household’s obligation.')
table(['Record','Example','Reviewed by'],[
 ['Bereaved household','HOUSEHOLD-PATEL-001','Community reviewer'],
 ['Participating household','HOUSEHOLD-001','Community reviewer'],
 ['Representative','Customer account attached to HOUSEHOLD-001','Different reviewer verifies evidence'],
], [1.6,3.05,2.15])

doc.add_heading('4. Configure the payout beneficiary', level=1)
para('Before creating a collection, ensure the organisation has a verified payout beneficiary configured in the shared payments administration area. Select a bank account, mobile-money destination or wallet as supported. The beneficiary must belong to the same organisation and use the collection currency (BWP in this example).')
para('The beneficiary destination is where the organisation receives the collected support. It is distinct from the bereaved household: the collection has a structured target household, while the organisation’s verified payout beneficiary identifies the settlement destination. Follow the community’s agreed procedure for passing the support to the family; the demo does not itself validate the family’s external bank ownership.')

page(); doc.add_heading('5. Open and activate a collection', level=1)
steps([
 ('Create the collection.', 'As creator, open Collections and choose the community. Select the verified organisation payout beneficiary and target household HOUSEHOLD-PATEL-001. Enter a clear purpose such as “Funeral assistance for the Patel household, funeral on 28 November” and add an evidence/reference note if required. The collection saves its contribution amount and currency for this collection.'),
 ('Submit it for review.', 'The creator submits the collection. Its status becomes under review. The creator cannot approve their own collection.'),
 ('Review and approve.', 'The community reviewer checks the target household, purpose, beneficiary, policy and supporting evidence, then approves or returns/rejects according to the available actions. Keep the review decision independent of the creator.'),
 ('Activate the approved collection.', 'The authorized reviewer activates it. The system snapshots household eligibility for the collection and creates one obligation for each eligible household using the collection’s saved amount, normally BWP 20.00. Exempt households are retained in the snapshot but receive no payable obligation.'),
 ('Notify household representatives.', 'Activation queues an in-app notice for each eligible household’s verified representative. The notice identifies the organisation/community, purpose and contribution amount and provides a link into the customer request. Delivery depends on the local notification worker/channel being available.'),
])
doc.add_heading('Example calculation', level=2)
table(['Eligible households','Contribution per household','Gross if every payment succeeds'],[
 ['5','BWP 20.00','BWP 100.00'],
], [1.7,2.25,2.85])
para('Gross received is not the same as payout amount. Fees, refunds, reversals and late-review payments can affect the reconciled net support amount.')

doc.add_heading('6. Customer contribution in the customer app', level=1)
steps([
 ('Sign in as the verified household representative.', 'Open the customer app or customer web app with the customer account that is verified for the household.'),
 ('Open Funeral Assistance.', 'Go to Services → Funeral Assistance. The obligation should show the organisation/community, the collection purpose, the BWP 20.00 amount, and its current payment state. If it is missing, refresh and confirm the customer is linked to the eligible household’s verified representative record.'),
 ('Review and pay.', 'Open the request and choose Pay. Select an available bank/card option for funeral_contribution and the organisation, then complete the provider checkout. For the local demo, this may open the mock-bank page.'),
 ('Return to the request.', 'After the provider reports success, the customer screen refreshes/polls until the funeral obligation confirms. Do not treat a provider success page alone as a completed funeral contribution; the obligation receipt must be confirmed by the funeral service.'),
])
para('The customer may see pending, failed, cancelled, paid, refunded or reversed states according to the payment and receipt records. A failed/cancelled attempt is not counted as a contribution. If the page continues to show pending, refresh once and ask an authorized operator to check the collection transaction history and payment correlation ID.')

page(); doc.add_heading('7. Close, reconcile and report', level=1)
para('When the agreed contribution window ends, the creator or authorized manager closes the collection. Closing stops new contribution payment intents. Review the collection report before asking for payout.')
table(['Review item','What it tells you'],[
 ['Eligible obligation count','How many households were included in the collection snapshot.'],
 ['Successful contribution count and confirmed gross','Only confirmed receipt records should be counted as successful received contributions.'],
 ['Pending or unresolved payments','Attempts still awaiting a final payment/receipt outcome; resolve these before payout.'],
 ['Failed or cancelled attempts','Attempt history that did not produce a contribution. These are not included in confirmed gross.'],
 ['Refunded, partially refunded or reversed','Amounts that reduce the available net support, with their status kept separate from successful receipts.'],
 ['Late-review receipts','Shown separately from normally confirmed receipts so they are not double-counted in confirmed gross.'],
 ['Fees and net support','Net amount available after applicable fees and refund/reversal adjustments.'],
], [2.45,4.35])
para('From the collection view, open Reconciliation & payout, load the aggregate report and inspect the collection-level transaction history. Export the transaction report (CSV) if the community needs a record to share or archive. Restrict the export to authorized staff because it may contain sensitive transaction references.')
doc.add_heading('8. Request and approve beneficiary payout', level=1)
steps([
 ('Confirm payout readiness.', 'The collection must be closed and reconciled; the organisation beneficiary must be verified and match the organisation and currency. Resolve pending payment/refund or late-review liabilities before requesting settlement.'),
 ('Request payout.', 'The creator/manager with community.funeral.payout.request submits the payout request. The requested amount is the reconciled net support, not the gross total. The record changes to awaiting approval.'),
 ('Independent review.', 'A different community reviewer with community.funeral.payout.approve examines the amount, destination, report and supporting record. The requester cannot approve their own payout. The reviewer can reject with a reason or approve.'),
 ('Record settlement.', 'When approved, the local ledger settlement posts and the disbursement is marked paid with a ledger journal ID, receipt reference, approver, and paid timestamp. The admin page displays the payout state and receipt/audit information.'),
 ('Confirm handoff to the family.', 'The authorized community representative follows the community’s agreed process to deliver the support to the bereaved household and keeps any separate acknowledgement or receipt required by local procedures.'),
])
para('Local demo limit: the mock-bank flow records an internal ledger settlement and mock provider reference; it does not send money through a real bank. A wallet destination can credit an eligible internal wallet. Never describe a local mock payment as an actual external bank transfer.')

doc.add_heading('9. Worked example from start to finish', level=1)
table(['Stage','Example action/result'],[
 ['Setup','Platform approver enables the funeral readiness policy. The local API mock-ledger setting is enabled and payment provider account is available for the organisation.'],
 ['Community','Creator creates Patel Funeral Support Community under Patel funeral Community and assigns a manager and reviewer.'],
 ['Households','Reviewer marks HOUSEHOLD-PATEL-001 and five participating households as eligible; each contributing household has one verified customer representative.'],
 ['Collection','Creator opens “Funeral assistance for the Patel household”, chooses HOUSEHOLD-PATEL-001 as target and the organisation’s verified BWP beneficiary. Reviewer approves and activates it.'],
 ['Contributions','Five eligible representatives each pay BWP 20.00. If all five receipts confirm, confirmed gross is BWP 100.00 before fees/refunds.'],
 ['Reconciliation','Creator closes the collection. Reviewer inspects transaction rows and status totals. Assume fees total BWP 2.00 and there are no refunds; net support is BWP 98.00. This is an illustrative calculation only.'],
 ['Payout','Creator requests BWP 98.00. A different reviewer approves. The local demo records a paid status, journal reference and receipt, then the community follows its separate family handoff procedure.'],
], [1.25,5.55])

page(); doc.add_heading('10. Troubleshooting and operating notes', level=1)
table(['What you see','Check this'],[
 ['Readiness unavailable / actions disabled','Confirm community_funeral.http_enabled is enabled and approved, local mock payout config is set if demonstrating payout, then clear API config cache and restart.'],
 ['Customer sees no request','Confirm collection is active; household is eligible in the collection snapshot; customer is a verified representative; app is signed in to that customer; refresh the Funeral Assistance page.'],
 ['No payment methods available','Enable an eligible provider account for the funeral_contribution context and the collection’s organisation/currency.'],
 ['Payment succeeded but obligation is pending','Wait for receipt confirmation; refresh once. Check provider callback, funeral receipt and obligation state before counting the payment.'],
 ['Payout action unavailable','Check collection is closed, net support is positive, there are no unresolved liabilities, the beneficiary is verified for the same organisation/currency, and the signed-in user has the payout request permission.'],
 ['Approver cannot approve own request','Use a separate reviewer account with community.funeral.payout.approve. Sign out and back in after changing role grants.'],
 ['Mock payout appears paid','It means the local ledger flow completed. It does not prove that money moved through an external bank.'],
], [2.25,4.55])

doc.add_heading('Permissions quick reference', level=2)
table(['Action','Expected permission'],[
 ['Manage community, households, collection setup','community.funeral.manage'],
 ['Review household, representative or collection','community.funeral.review'],
 ['View reports and transaction export','community.funeral.report'],
 ['Request beneficiary payout','community.funeral.payout.request'],
 ['Approve or reject beneficiary payout','community.funeral.payout.approve'],
 ['Change the readiness policy','configuration policy update/approval rights assigned by the admin access model'],
], [3.1,3.7])
doc.add_heading('Privacy and record handling', level=2)
para('Use household references in routine screens and reports. Share transaction exports only with people authorized by the community. Record only the evidence needed for review, keep payout approvals independent, and retain a record of how the community handed the support to the bereaved family.')
doc.add_heading('Service boundary', level=2)
para('Segotsamollo preserves community leadership and solidarity. OleKard coordinates requests, captures payment and review records, and supports transparent reporting. The current local bank path is a mock ledger demonstration; production bank payout depends on a separately configured external payout integration and operating approval.')

doc.core_properties.title='Funeral Assistance User Guide'
doc.core_properties.subject='Step-by-step local guide for community funeral collections, household contributions, reconciliation and payout'
doc.core_properties.author='OleKard Ecosystem'
doc.save(OUT)
print(OUT)
