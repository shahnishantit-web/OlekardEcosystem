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
para('Enable the local readiness controls → create a community → register and review households → link verified customer representatives or record consented phone contacts → configure a verified organisation payout destination → create, approve and activate a collection → customers pay in-app or invited households join OleKard and claim their household → close and reconcile → request payout → independent reviewer approves and records settlement.')
para('Admin involvement is limited to one-time platform readiness and shared payment configuration. Once those prerequisites are enabled, an authorised community manager and reviewer can run each collection through the community workflow without asking an Admin user to create, approve, activate or close that collection.')

page(); doc.add_heading('1. Users and responsibilities', level=1)
para('The workflow separates setup, review and payment actions. A user should only perform actions granted to their account and assigned to the relevant organisation or community. The collection maker and payout approver must be different people.')
table(['Role','Typical user','What they do'],[
 ['Platform administrator','Admin user with configuration policy rights','Completes one-time readiness and shared payment setup. This role is not needed for routine collection actions after setup.'],
 ['Community manager / creator','A named, active member of the organisation with community manager access','Creates the community, adds household references and representatives, prepares and submits collections, closes collections, and requests payout.'],
 ['Community reviewer','A different named, active organisation member assigned the reviewer role','Independently reviews household eligibility and representatives, approves and activates collections, reviews reports, and can approve or reject payout if granted that permission.'],
 ['Household representative / customer','A verified customer account linked to a participating household','Receives the request, checks the collection details and contributes through the customer app.'],
], [1.45,2.05,3.3])
para('Use your approved account roster to obtain sign-in details. This guide intentionally contains no passwords, PINs, OTPs, tokens, or personal phone numbers. After a role or permission change, sign out and back in to refresh the session permissions.')

doc.add_heading('2. Before starting', level=1)
steps([
 ('Start the local services.', 'Run the API and customer/admin web apps against the local database. PostgreSQL is expected on port 5432 in this workspace. Redis is not required for the described page unless the local API configuration enables a Redis-backed feature.'),
 ('Enable Funeral Assistance.', 'In Admin, open Configuration → Operational Policy → community_funeral.http_enabled, turn it on, and apply the change. If the setting is controlled by approval, submit it and have the authorized configuration approver approve it.'),
 ('Enable local mock payout ledger support.', 'For local demonstration only, set PAYMENT_MOCK_BANK_LOCAL_FUNERAL_LEDGER_ENABLED=true in the API environment, then clear cached configuration with php artisan config:clear and restart the API.'),
 ('Confirm provider setup for contributions.', 'For the funeral_contribution context and the organisation, enable an eligible mock bank or card provider account. Without an eligible provider account, checkout will report that no payment method is available.'),
 ('Confirm organisation and household readiness.', 'The organisation must exist and be active. The community creator and assigned staff need active organisation membership. Before activation, every eligible household needs either a verified customer representative or a consented phone contact for an invitation.'),
])
para('A readiness message in Admin means one or more prerequisites are still closed. The page may show the workflow, but setup or payment actions remain unavailable until the readiness gate is open. After the one-time gate is enabled, community roles manage the collection lifecycle; no per-collection Admin approval is part of this flow.')

page(); doc.add_heading('3. Create the community and prepare households', level=1)
doc.add_heading('Step 1 — Create the community', level=2)
para('Sign in as the community creator and open Admin → Community → Funeral Assistance. In Community setup, choose the organisation “Patel funeral Community”, enter “Patel Funeral Support Community”, and create it. The community is a funeral-assistance group and is not an event.')
para('Assign community staff in the community’s staff section. Add the creator as manager and the reviewer as reviewer. Use named people from the available user selection where possible; UUIDs are internal identifiers and should not be typed by an operator.')
doc.add_heading('Step 2 — Add household references', level=2)
para('In Households & representatives, add each family using a private reference rather than exposing full personal details. For example, create HOUSEHOLD-PATEL-001 for the bereaved household and HOUSEHOLD-001 through HOUSEHOLD-005 for participating families. A private reference should be meaningful to authorized community staff but should not contain unnecessary sensitive details.')
doc.add_heading('Step 3 — Review eligibility', level=2)
para('The reviewer selects each pending household and marks it eligible or exempt. An exemption should have an explanation or evidence reference. Eligible households are included in a collection’s contribution snapshot; exempt households are recorded but are not charged the P20 obligation.')
doc.add_heading('Step 4 — Link an existing customer or invite a new one', level=2)
para('There are two supported paths. For an existing OleKard customer, link the customer account to the household, enter the required evidence reference and have a separate reviewer verify the representative. That customer can receive the in-app notice and pay the household obligation.')
para('For a person without an OleKard account, add the household using its private reference, enter the representative phone in international E.164 format (for example, +267…), and record the person’s consent to use that number for funeral and account-joining messages. Do not add an account representative yet. The reviewer can approve household eligibility without an account. Before activation, every eligible household must have either a verified customer representative or a consented phone contact.')
table(['Household path','Required before activation','What happens next'],[
 ['Existing OleKard customer','Customer linked to household and representative verified by reviewer','In-app collection notice; customer opens Funeral Assistance and pays.'],
 ['No OleKard account yet','Consented contact phone recorded; household eligibility approved','One-time collection invitation is sent by the configured SMS channel; recipient joins OleKard, verifies the same phone and claims the household.'],
], [1.65,2.5,2.45])
para('The phone is stored encrypted. An invitation is specific to a collection, identifies the organisation/community, purpose and contribution amount, and provides a join link. The invitation token is one-use, expires after 30 days, and can only be claimed by an active CUSTOMER account whose verified phone exactly matches the invited phone. The household table shows invitation state; authorized community managers can copy an active join link for manual sharing.')

doc.add_heading('4. Configure the payout beneficiary', level=1)
para('Before creating a collection, ensure the organisation has a verified payout beneficiary configured in the shared payments administration area. Select a bank account, mobile-money destination or wallet as supported. The beneficiary must belong to the same organisation and use the collection currency (BWP in this example).')
para('The beneficiary destination is where the organisation receives the collected support. It is distinct from the bereaved household: the collection has a structured target household, while the organisation’s verified payout beneficiary identifies the settlement destination. Follow the community’s agreed procedure for passing the support to the family; the demo does not itself validate the family’s external bank ownership.')

page(); doc.add_heading('5. Open and activate a collection', level=1)
steps([
 ('Create the collection.', 'As creator, open Collections and choose the community. Select the verified organisation payout beneficiary and target household HOUSEHOLD-PATEL-001. Enter a clear purpose such as “Funeral assistance for the Patel household, funeral on 28 November” and provide the supporting evidence reference required for approval. The collection saves its contribution amount and currency for this collection.'),
 ('Submit it for review.', 'The creator submits the collection. Its status becomes under review. The creator cannot approve their own collection.'),
 ('Review and approve.', 'The community reviewer checks the target household, purpose, beneficiary, policy and supporting evidence, then approves or rejects the collection. The reviewer must be different from the creator.'),
 ('Activate the approved collection.', 'The authorized reviewer activates it. The system snapshots household eligibility for the collection and creates one obligation for each eligible household using the collection’s saved amount, normally BWP 20.00. Exempt households are retained in the snapshot but receive no payable obligation.'),
 ('Notify eligible households.', 'Existing verified customer representatives receive an in-app notice. For an eligible household with a consented phone but no account, activation queues a one-time SMS invitation for this collection. The invitation identifies the organisation/community, purpose and contribution amount and links to Join OleKard. Each eligible household needs one of these two notification paths for activation to proceed.'),
])
para('Local demo delivery: the default LogSmsChannel records a masked invitation message in the API log; it does not send a real SMS. Use the household row’s Copy join link action to share the active link manually with the consenting recipient. Configure an approved SMS channel before relying on text delivery outside local development. The raw invitation token is not written to the log.')
doc.add_heading('Example calculation', level=2)
table(['Eligible households','Contribution per household','Gross if every payment succeeds'],[
 ['5','BWP 20.00','BWP 100.00'],
], [1.7,2.25,2.85])
para('Gross received is not the same as payout amount. Fees, refunds, reversals and late-review payments can affect the reconciled net support amount.')

doc.add_heading('6. Customer contribution in the customer app', level=1)
doc.add_heading('Path A — Existing OleKard customer', level=2)
steps([
 ('Sign in as the verified household representative.', 'Open the customer app or customer web app with the customer account that is verified for the household.'),
 ('Open Funeral Assistance.', 'Go to Services → Funeral Assistance. The obligation should show the organisation/community, the collection purpose, the BWP 20.00 amount, and its current payment state. If it is missing, refresh and confirm the customer is linked to the eligible household’s verified representative record.'),
 ('Review and pay.', 'Open the request and choose Pay. Select an available bank/card option for funeral_contribution and the organisation, then complete the provider checkout. For the local demo, this may open the mock-bank page.'),
 ('Return to the request.', 'After the provider reports success, the customer screen refreshes/polls until the funeral obligation confirms. Do not treat a provider success page alone as a completed funeral contribution; the obligation receipt must be confirmed by the funeral service.'),
])
para('The customer app waits for both payment success and the funeral obligation record to confirm before showing the contribution as complete. A failed or cancelled attempt is not a contribution. Authorized community staff can inspect the transaction history for payment, refund, reversal, and late-review states if the customer still sees a pending status.')
doc.add_heading('Path B — Recipient does not yet have an OleKard account', level=2)
steps([
 ('Open the invitation.', 'The recipient opens the SMS invitation or the manager-shared active join link. Check the organisation/community, funeral purpose and BWP 20.00 request before continuing. If the request is unexpected, do not join or pay; contact the community representative through a known channel.'),
 ('Join OleKard.', 'Choose Join OleKard and complete customer registration, phone verification by OTP, and PIN setup. Use the same phone number that received the invitation. A person who already has an account can choose sign in instead.'),
 ('Claim the household.', 'After authentication, the app returns to the invitation and claims it automatically. The API checks CUSTOMER account type, active account status, the verified phone match, invitation expiry and one-time use. On success, the account is linked as the household representative and the invitation token is cleared.'),
 ('Review and contribute.', 'Open Services → Funeral Assistance. The household obligation shows the community, purpose, amount and payment state. Choose an available funeral_contribution bank/card option and complete checkout as described in Path A.'),
]) 
para('The app keeps the invitation pending through registration or sign-in so the person does not need to copy a household ID. If claiming fails, confirm the phone number exactly matches the invited number, the account is an active customer, and the link has not expired or already been used. Ask the community manager to issue a fresh invitation when needed.')
para('For a phone/emulator separate from the local development computer, configure CUSTOMER_APP_URL to a host/IP reachable from that device. A link using localhost points back to the recipient device itself and will not reach the developer computer.')

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
 ['Contribution channel','Transaction rows label digital payments as in_app (OleKard app). The current workflow does not support entering or reviewing offline cash contributions; do not include an unrecorded offline payment in platform totals.'],
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
 ['No account invitation was not sent','Check household eligibility and consented E.164 phone, then check the invitation status on the household row. In local mode LogSmsChannel only writes a masked message to the API log; use Copy join link to share it manually.'],
 ['Join link says invalid, expired or already used','The token is one-use and expires after 30 days. Confirm the signed-in active CUSTOMER account has verified the exact invited phone number; have the manager issue a new invitation if required.'],
 ['Invitation opens but app cannot load','If opening from another device, set CUSTOMER_APP_URL to an IP/host reachable from that device rather than localhost.'],
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
para('Use household references in routine screens and reports. Collect and store contact details only with consent, use invitation links only for the intended recipient, and do not share them publicly. Share transaction exports only with people authorized by the community. Record only the evidence needed for review, keep payout approvals independent, and retain a record of how the community handed the support to the bereaved family.')
doc.add_heading('Service boundary', level=2)
para('Segotsamollo preserves community leadership and solidarity. OleKard coordinates requests, captures payment and review records, and supports transparent reporting. The current local bank path is a mock-ledger demonstration, not an external bank transfer. A wallet payout credits an eligible OleKard wallet through the ledger. A real bank payout requires a separately implemented and approved external payout integration. Offline cash contributions are not currently recorded in this workflow.')

doc.core_properties.title='Funeral Assistance User Guide'
doc.core_properties.subject='Step-by-step local guide for community funeral collections, household contributions, reconciliation and payout'
doc.core_properties.author='OleKard Ecosystem'
doc.save(OUT)
print(OUT)
