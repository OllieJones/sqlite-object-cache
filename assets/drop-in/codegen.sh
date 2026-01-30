#!/bin/bash
/usr/bin/cpp -fmax-include-depth=1 -C -P -nostdinc ./assets/drop-in/object-cache-template.php ./assets/drop-in/object-cache.php
/usr/bin/cpp -fmax-include-depth=1 -C -P -DNOSTATS -DIGBINARY -DSQLITE383 -DSQLITE324 -nostdinc ./assets/drop-in/object-cache-template.php ./assets/drop-in/fast-object-cache.php
