# Payment source provenance

Read-only OOXML extraction on 2026-10-22 retained every paragraph, including
table-cell paragraphs in document order. These text files preserve source content;
they do not claim to preserve Word layout or grant provider certification.

The original architecture Word document is also retained unchanged as
`docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx`; its SHA256 matches the
verified Downloads source below. Future work can read this repository copy.

| Source | Internal version | SHA256 of original DOCX | Retained text |
|---|---|---|---|
| `C:/Users/BAPS/Downloads/OleKard_Shared_Payment_Architecture.docx` | 1.1, 19 October 2026 | `B5D07562EE2497C726AABEFE18782ABFFC094A39CED8D0CF3E16AA0F2CFF7837` | `OleKard_Shared_Payment_Architecture_v1.1.txt` |
| `C:/Projects/OleKardEcosystem/OleKard_Claude_SpecKit_Managed_Prompts.docx` | 1.0, 20 October 2026; architecture baseline 1.1 | `B05E02FA10504F3083009C0F2A5075A7EC617D2810F09D92F8DABBA2BC082635` | `OleKard_Managed_Prompts_v1.0.txt` |

P10 reviewed FD06 (text line 124), LC01 (359), LC03 (381), LC05 (404),
section 1 (architecture boundaries), section 8 (558), and managed P10 (406).
Older P00–P09 artifacts saying the architecture is inaccessible are historical;
this discovery resolves document availability, not their implementation evidence.
