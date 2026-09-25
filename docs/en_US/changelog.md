# Changelog

## 0.2

- Default alert thresholds on supplies: warning below 20 % and danger below
  10 % for toner and ink, warning below 10 % and danger below 5 % for the drum
  and other wear parts. Set once on existing commands, never overriding a
  threshold set by hand.
- New "Pages today" and "Pages this month" commands, reset at midnight and on
  the 1st, even when the printer is off. Shown on the tile.
- Polling every minute while an error is reported (paper, jam…), back to the
  chosen interval afterwards.

## 0.1

First release.

- Local polling over SNMP v2c with a built-in PHP SNMP client: no dependency,
  not even the php-snmp extension.
- Laser and inkjet, monochrome and colour, recent and legacy Brother
  maintenance block formats; technology detected from the model name.
- Toner or ink per colour, drum, belt, fuser, laser unit, paper feed kits,
  pages left before maintenance.
- Page counters: total, black and white, colour, duplex, per colour.
- Display text, printing state, device state, errors in plain words, last boot,
  online presence.
- Commands only created for data the printer reports.
- Dashboard tile: supply bars, counters, error banner.
- "Test address" button and Diagnostic tab with the raw answer.
- Printer turned off: last values kept, attempts spaced out.
