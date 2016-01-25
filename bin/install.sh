#!/bin/bash
##
# Installation script for mbc-user-import
##

# Assume messagebroker-config repo is one directory up
cd ../messagebroker-config

# Gather path from root
MBCONFIG=`pwd`

# Back to mbc-user-import
cd ../mbc-user-import

# Create SymLink for mbc-user-import application to make reference to for all Message Broker configuration settings
ln -s $MBCONFIG .
