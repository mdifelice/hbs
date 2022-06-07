#!/usr/bin/python3
# -*- coding: utf8

import argparse
import yaml
from repos import ieee

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
    
    '''
    TODO: Migrar esto a una función que parseé los argumentos.
    if not args.title:
        holaMundo()
    else:
        holaMundo(args.title)
    '''

    print("Cargando archivo de configuración")
    cfg = read_yaml("config.yml") # TODO: Pendiente hacer chequeo de errores

    print("Cargando clases de repositorios")    
    repo = ieee(cfg['IEEE']['basePath'], cfg['IEEE']['apikey'])
    repo.add_query_param('mini review machine learning applications')
    repo.add_query_param(2022,'from_year')
    repo.search()
    repo.validate_dictionary()

    print("Fin de ejecución")