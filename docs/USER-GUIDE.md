# User guide

For technicians. The assistant reads the ticket and proposes; **you** decide what is sent, applied
or published.

- [The "AI assistant" button](#the-ai-assistant-button)
- [Analysis](#analysis)
- [Reply to the requester](#reply-to-the-requester)
- [Improve my text](#improve-my-text)
- [Classification](#classification)
- [Knowledge base article](#knowledge-base-article)
- [Good practices](#good-practices)
- [Questions](#questions)

## The "AI assistant" button

At the bottom of the timeline of a ticket. It opens a window with tabs:

| Tab | Available |
|---|---|
| Analysis | open tickets |
| Reply to the requester | open tickets |
| Classification | open tickets |
| Knowledge base | solved and closed tickets |

When an automatic suggestion exists, a second button **Suggested category (xx %)** opens the
Classification tab directly.

## Analysis

The assistant reads the whole ticket: description, followups, tasks, solutions (refused ones
included), linked assets, approval, and the attachments of every item of the timeline (screenshots,
PDF, Word/Excel, logs). It also looks for **similar tickets your team already solved**. It takes from
a few seconds to about a minute.

You get, as **internal guidance** (never sent to the requester):

- **Summary**: the problem and what was already done;
- **Technical strategy**: probable cause, what to check, step by step, when to escalate;
- **Information still needed**: what to ask the requester;
- **Similar solved tickets used as references**: links to them, with their similarity (or "same
  category" for a recent ticket of the same category).

The line at the bottom shows the model, the tokens and the time. **Generate again** asks for a new
analysis; when the ticket has not changed, the last analysis is shown again at no cost.

👍 / 👎 tell the administrators whether the answer helped; they appear in the statistics.

## Reply to the requester

A draft written in the communication standard of your team, greeting the requester by first name,
with your signature.

- **Insert as followup** / **Insert as solution**: opens the answer form of GLPI and puts the draft in
  the editor. If the editor already contains text, you are asked before it is replaced
  (Ctrl+Z undoes it).
- **Copy**: copies the text, to paste anywhere.

**Read and edit it before sending**: check the facts, the commands, the names. The followup is
sent only when you click **Add** in GLPI, with the visibility (private or public) you choose.

## Improve my text

In the **followup** and **solution** forms, under the editor: **Improve my text (AI)**, and the
arrow next to it for the other modes:

| Mode | What it does |
|---|---|
| Improve my text | clearer, polite, structured, in the standard of the team, same facts |
| More formal | for a manager, an external partner, a sensitive case |
| Friendlier | warmer, simpler words |
| Shorter | keeps only what matters |
| Fix spelling and grammar only | changes nothing else (no greeting, no signature added) |
| Translate to the requester's language | uses the language of the requester's GLPI profile |

Write the facts quickly, in your own words, then improve: the text in the editor is replaced, and
**Ctrl+Z** brings yours back. The AI keeps your facts; it does not add promises or deadlines you did
not write.

## Classification

The assistant suggests the **type** (incident or request), the **category** and the **urgency**,
from the similar solved tickets and, when enabled, the AI's own reading of the ticket.

- The table compares the current values with the suggestion; the bar shows the confidence.
- **Apply to the ticket** applies the type, category and urgency (with your rights on the ticket).
- **Other candidates** lists the next categories, each with **Apply the category**.
- **Dismiss** removes the suggestion.

Your choices are recorded: they measure how often suggestions are right, and help decide whether
new tickets can be classified automatically.

## Knowledge base article

On a solved or closed ticket, the **Knowledge base** tab writes an article (symptoms, cause,
solution) without the personal data of the ticket.

- Edit the title, **Copy** the text, or **Create a draft article**: the article is created
  **unpublished** and linked to the ticket. **Open the article** to review it and choose its
  audience (targets) before publishing it.

## Good practices

- **Check what you send.** The AI can be wrong or outdated (versions, commands, internal rules).
- **Private notes stay private.** Internal notes may help the analysis, but the reply draft never
  quotes them; still, read the draft before sending.
- **Never write passwords in tickets.** The assistant masks values written as "password: …", but
  not every secret can be detected.
- **Good tickets give good answers**: a clear description, the error message, a screenshot.
- **Use 👍 / 👎**: it is the simplest way to tell the administrators what works.

## Questions

**I do not see the "AI assistant" button.** The assistant may be disabled, your profile may not
have the right "AI assistant", or the ticket is closed (only the Knowledge base tab is offered).
Ask your administrator.

**"The daily budget of the AI assistant is exhausted" / "You reached the limit of N uses of the
assistant per hour".** Limits set by your administrator to control costs; try later.

**Does the requester see anything?** No. Nothing is sent to the requester unless you insert a draft
and add the followup or solution yourself.

**Can it read screenshots and documents?** Yes, when your administrator enabled it and the model
reads images: screenshots, scanned pages, PDF, Word, Excel, PowerPoint, OpenDocument and text files
(logs, CSV, JSON…), within the configured limits.
