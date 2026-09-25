# Brother plugin

Monitors **Brother** printers, laser and inkjet alike, over the local network
through SNMP. No cloud, no account, no dependency to install: the plugin talks
to the printer directly.

## What it reports

Depending on what your printer exposes:

- **Supplies**: toner or ink level per colour, remaining life of the drum, belt,
  fuser, laser unit and paper feed kits, pages left before replacement, ink
  waste box.
- **Counters**: printed pages, black and white, colour, duplex, per-colour
  counters, drum pages, and **pages today** and **this month**.
- **State**: text shown on the printer display, printing state (idle,
  printing, warm-up), device state, last boot date.
- **Errors**: no paper, jam, cover open, low or empty toner, missing tray,
  output tray full… in plain words in the *Erreurs* command, summed up by the
  *En erreur* binary command.
- **Online**: whether the printer answers.

Commands are only created for data the printer reports: a monochrome laser
will never get a "cyan toner" command. They appear after the first successful
poll.

Values are decoded with the same tables as the Home Assistant Brother
integration: both show the same figures.

## Requirements

- A networked Brother printer with **SNMP enabled** (factory default, read-only
  with the `public` community).
- A **fixed IP address** for the printer, reserved in your router.
- **UDP port 161** reachable from Jeedom.

## Setup

Plugins → Monitoring → Brother → **Ajouter une imprimante**, then enter the IP
address, the SNMP community (`public` by default), the technology (automatic
detection from the model name: `L` for laser, `J` or `T` for inkjet) and the
polling interval. **Tester l'adresse** queries the printer without saving and
shows its model, serial number, firmware and MAC address.

## Supply alerts

Percentage commands get Jeedom alert thresholds when created: toner and ink
warn at 20 % and turn danger at 10 %; drum and other wear parts at 10 % and
5 %. Change or remove them in each command's advanced configuration.

## Polling during an error

While the printer reports an error (paper, jam, cover open…), it is polled
every minute, then back to the chosen interval.

## Dashboard

A single tile sums up the printer: display text, one bar per supply (red below
10 %), page counts, and a red banner for current errors. Widget parameters:
`low` (red threshold, default 10) and `facts` = `0` to hide page counters.

## Printer turned off

The *En ligne* command drops to 0 and **no value is reset to zero**. After three
failures, attempts are spaced out from five minutes up to one hour.

## Privacy

Everything stays on your local network; the plugin only performs SNMP reads.

This plugin is not affiliated with Brother.
