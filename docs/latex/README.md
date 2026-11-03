# Lab 2 LaTeX report

[Open the compiled PDF](lab2-report.pdf).
The report was updated and rebuilt on 26 October 2026: fifteen A4 pages comprising
the reference-style university cover, a clickable table of contents, five report
pages, and eight screenshot-appendix pages. The final PDF was checked for all six
required sections, the edited student details, seventeen screenshot placements
(sixteen distinct evidence images), and
text staying within the margins. The cover uses a separate university logo in
`images/university-logo.png`; the website's spa logo is unchanged.

The main report and earlier screenshot captions use the professor-facing
wording from the revised `lab2-report.tex` supplied in Downloads. The working
LaTeX configuration, cover details, and complete new Task 5 evidence appendix
were preserved. Sessions now have a dedicated page, increasing the total from
fourteen to fifteen pages.

Database Overview includes an additional evidence page with the three supplied
phpMyAdmin captures: service records, categories, and the user-table structure.
Their originals are preserved in `docs/screenshots/database-*-evidence.png`.

The appendix's Task 2 confirmation page presents the MySQL service record first
and the matching website details second. A comparison table confirms Swedish
Massage, price `550000` / `550,000 VND`, and duration 60 minutes. These are existing
saved captures, not a fresh live-update demonstration.

Task 5 now has three dedicated evidence pages (physical PDF pages 13–15):
the saved cookie, persistence after reload/navigation, and persistence after
authenticated logout. Each pairs the real page with Chrome's native Application
cookie view. The value stays `dark` and the exact original expiry stays
`2026-11-25T14:31:10.877Z`. The configured lifetime is 30 days; the capture checks
immediate persistence across those actions, not 30 elapsed days.
Cookie images are cropped in LaTeX to enlarge the relevant table columns;
full-resolution originals and safe metadata remain in `docs/screenshots/`.
The temporary customer account and isolated Chrome profile were removed after
the successful capture. No session identifiers or passwords appear in the evidence.

Open `lab2-report.tex` to edit the report. Change the values inside the braces in
the `\StudentName`, `\StudentID`, and `\StudentClass` definitions near the top.
Keep the macro names used by the cover unchanged: a leading backslash starts a
LaTeX command, so do not replace `\StudentID` with `\2353003`, for example.
The Vietnamese name uses TeX accents for the existing fonts and Unicode text for
PDF metadata. The optional advisor row is currently omitted.
The cover date is October 2026. The documented test results are from the
recorded 23 October run, not a new run performed while writing the report.

## Compile with the VS Code extension

LaTeX Workshop (`James-Yu.latex-workshop`, installed version 10.20.0) is configured
in the project's `.vscode/settings.json` to use the portable Tectonic 0.17.0
compiler at `tools/latex/tectonic.exe`. No system PATH change is required.
The recipe stores this computer's absolute compiler path; update that command
in `.vscode/settings.json` if you move the project to another directory.

1. Open the **project folder** in VS Code, not just this subfolder.
2. If VS Code asks about Workspace Trust, trust this project to enable the
   extension. Do not trust unrelated folders merely to use LaTeX.
3. Press **Ctrl+P**, enter `docs/latex/lab2-report.tex`, and open it.
4. Click inside the LaTeX source, then press **Ctrl+Alt+B** to run
   **LaTeX Workshop: Build LaTeX project**. The recipe is **Tectonic (Lab 2)**.
5. Open `lab2-report.pdf`, or use **LaTeX Workshop: View LaTeX PDF file**.

If the log says **Active document is not a TeX file**, select the source-file tab
before building; the Output panel, PDF viewer, and Markdown files are not TeX
source. Select **LaTeX Workshop** or **LaTeX Compiler** in the Output dropdown to
inspect any build errors. The first build downloads required TeX packages/fonts;
subsequent builds reuse the local cache.

Saving or externally changing a watched TeX file also triggers an extension
build. The generated PDF, SyncTeX data, and compilation log stay beside the
source. Keep `docs/screenshots/` in place: Database Overview includes three images
and the appendix includes fourteen placements, all using relative paths. This project
also contains the website source and
the separate SQL deliverable; the PDF does not replace those submissions.

## Direct compiler check

The extension is the normal editing workflow. For terminal troubleshooting,
the same compiler can be run explicitly from this directory:

```powershell
& '..\..\tools\latex\tectonic.exe' --synctex --keep-logs '.\lab2-report.tex'
```

The compiler was downloaded from the official Tectonic 0.17.0 Windows release.
Archive SHA-256:
`f61ce51f0b0ade1015b7de7ef368541c5424e9756ecbd0d7af97d6d48030845f`.
The compiler can be omitted from the submitted source archive if size matters;
keep the `.tex`, screenshots, and final PDF, and install/configure a local compiler
on any other computer before rebuilding.

References: [LaTeX Workshop build recipes](https://github.com/James-Yu/LaTeX-Workshop/wiki/Compile),
[Tectonic installation](https://tectonic-typesetting.github.io/book/latest/installation/).
