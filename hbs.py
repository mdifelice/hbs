#!/usr/bin/python3
# -*- coding: utf8

import argparse
import yaml

# Create the arguments parser
parser = argparse.ArgumentParser()
parser.add_argument('--title', type=str, required=False)
parser.add_argument('--abstract', type=str, required=False)
parser.add_argument('--start-year', type=str, required=False)
parser.add_argument('--end-year', type=str, required=False)


def read_yaml(file_path):
    with open(file_path, "r") as f:
        return yaml.safe_load(f)

def holaMundo(name='Mysterious Someone'):
    print("Hola " + name + " :)")


if __name__ == "__main__" :
    args = parser.parse_args()
    
    if not args.title:
        holaMundo()
    else:
        holaMundo(args.title)

    yaml = read_yaml("config.yml")
    print(yaml)
    print(yaml['IEEE']['apikey'])
    print("Adios mundo :O")