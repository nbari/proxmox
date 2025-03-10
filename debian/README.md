Create iso for automated installation.

Extract the iso to a directory.

```bash
# Create a temporary directory
tmpdir=$(mktemp -d)

# Extract the ISO
xorriso -osirrox on -indev debian-12.9.0-amd64-netinst.iso -extract / $tmpdir
```

It will output something like:

```
xorriso 1.5.6 : RockRidge filesystem manipulator, libburnia project.

Copying of file objects from ISO image to disk filesystem is: Enabled
xorriso : NOTE : Loading ISO image tree from LBA 0
xorriso : UPDATE :    1585 nodes read in 1 seconds
xorriso : NOTE : Detected El-Torito boot information which currently is set to be discarded
Drive current: -indev 'debian-12.9.0-amd64-netinst.iso'
Media current: stdio file, overwriteable
Media status : is written , is appendable
Boot record  : El Torito , MBR isohybrid cyl-align-on GPT APM
Media summary: 1 session, 323584 data blocks,  632m data, 1585g free
Volume id    : 'Debian 12.9.0 amd64 n'
xorriso : UPDATE :    1585 files restored ( 748.0m) in 1 seconds = 566.3xD
Extracted from ISO image: file '/'='/tmp/tmp.fAewFvtO70'
```

Update the `isolinux.cfg` file to contain the following:

```bash
DEFAULT install
TIMEOUT 0
PROMPT 0

LABEL install
  MENU LABEL Automatic Install
  KERNEL /install.amd/vmlinuz
  APPEND auto=true priority=critical vga=788 initrd=/install.amd/initrd.gz --- quiet
```

Rebuilt the ISO:

```bash
cd $tmpdir
xorriso -as mkisofs -o /tmp/debian-auto.iso \
  -isohybrid-mbr /usr/lib/syslinux/bios/isohdpfx.bin \
  -c isolinux/boot.cat \
  -b isolinux/isolinux.bin \
  -no-emul-boot -boot-load-size 4 -boot-info-table .
```

> Note: The `isohdpfx.bin` file is located in `/usr/lib/syslinux/bios/` on Arch Linux., you need to install it `paru -Syu syslinux`

Output should be something like:

```
xorriso 1.5.6 : RockRidge filesystem manipulator, libburnia project.

Drive current: -outdev 'stdio:/tmp/debian-auto.iso'
Media current: stdio file, overwriteable
Media status : is blank
Media summary: 0 sessions, 0 data blocks, 0 data, 62.1g free
Added to ISO image: directory '/'='/tmp/tmp.fAewFvtO70'
xorriso : UPDATE :    1585 files added in 1 seconds
xorriso : UPDATE :    1585 files added in 1 seconds
xorriso : NOTE : Copying to System Area: 432 bytes from file '/usr/lib/syslinux/bios/isohdpfx.bin'
libisofs: NOTE : Aligned image size to cylinder size by 266 blocks
xorriso : UPDATE :  22.60% done
ISO image produced: 384512 sectors
Written to medium : 384512 sectors at LBA 0
Writing to 'stdio:/tmp/debian-auto.iso' completed successfully.
```

The `debian-auto.iso` file is the new ISO with the automated installation.
