#!/bin/sh
CONSOLE=/home/loyd/ss2/src/cake/console
cd /home/loyd/ss2/src/app
./vendors/cakeshell scores -cli /usr/bin -console $CONSOLE -type espn -all 5 #-date "2010-06-28"
